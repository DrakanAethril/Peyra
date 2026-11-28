<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Storage;

use App\Service\Shared\Storage\DossierFiles;
use App\Service\Shared\Storage\S3ClientFactory;
use App\Service\Shared\Storage\UploadPolicy;
use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Yaml\Yaml;

/**
 * The IAM policy of infra/terraform/iam.tf against what Peyra really sends to S3 - « ni plus, ni
 * moins ». Every public gesture of DossierFiles is played on the production stack (the client of
 * S3ClientFactory, Flysystem's adapter, the options of config/packages/flysystem.yaml) whose last
 * handler is replaced: the requests are built, serialised and signed, then answered here. Nothing
 * reaches AWS, no credential is looked up.
 *
 * The S3 operations seen are mapped to the IAM actions AWS documents for them
 * (https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-with-s3-policy-actions.html), and
 * that set must be the policy's, exactly.
 */
final class S3BucketPolicyTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../../..';
    private const string BUCKET = 'exemple-peyra-documents';

    /** @var array<string, list<string>> S3 operation => the IAM actions it needs */
    private const array ACTIONS_OF = [
        'HeadObject' => ['s3:GetObject'],
        'GetObject' => ['s3:GetObject'],
        'PutObject' => ['s3:PutObject'],
        'CreateMultipartUpload' => ['s3:PutObject'],
        'UploadPart' => ['s3:PutObject'],
        'CompleteMultipartUpload' => ['s3:PutObject'],
        'CopyObject' => ['s3:GetObject', 's3:PutObject'],
        'ListObjectsV2' => ['s3:ListBucket'],
        'DeleteObject' => ['s3:DeleteObject'],
        'DeleteObjects' => ['s3:DeleteObject'],
    ];

    /** The public methods of DossierFiles this test plays; one more fails the test until played. */
    private const array PLAYED = ['prefix', 'storeUpload', 'storeContents', 'read', 'readStream', 'exists', 'size', 'copyAll', 'deleteAll', 'owned'];

    /** @var list<array{operation: string, key: ?string, prefix: ?string, copySource: ?string, acl: ?string, aclHeader: string}> */
    private array $sent = [];

    /** @return iterable<string, array{string}> */
    public static function prefixes(): iterable
    {
        yield 'a bucket of its own' => [''];
        yield 'a folder of a shared bucket' => ['peyra/'];
    }

    #[DataProvider('prefixes')]
    public function testThePolicyGrantsWhatDossierFilesSendsAndNothingMore(string $prefix): void
    {
        $this->playEveryGesture($prefix);

        $needed = [];
        foreach ($this->sent as $request) {
            self::assertArrayHasKey($request['operation'], self::ACTIONS_OF, \sprintf('%s is sent to S3: map it to its IAM actions (AWS documentation above), then grant them in infra/terraform/iam.tf.', $request['operation']));
            foreach (self::ACTIONS_OF[$request['operation']] as $action) {
                $needed[$action] = true;
            }
        }
        $needed = array_keys($needed);

        $expected = $needed;
        sort($expected);

        self::assertSame($expected, $this->grantedActions(), 'infra/terraform/iam.tf must grant exactly what Peyra sends to S3.');
    }

    #[DataProvider('prefixes')]
    public function testNoRequestCarriesAnAclNorReadsOne(string $prefix): void
    {
        $this->playEveryGesture($prefix);

        self::assertNotSame([], $this->sent);
        foreach ($this->sent as $request) {
            self::assertNull($request['acl'], $request['operation'].' must carry no ACL: the bucket has ACLs disabled.');
            self::assertSame('', $request['aclHeader'], $request['operation'].' must send no x-amz-acl header.');
            self::assertStringNotContainsString('Acl', $request['operation']);
        }
    }

    #[DataProvider('prefixes')]
    public function testEveryRequestStaysInsideThePrefixThePolicyConfinesTo(string $prefix): void
    {
        $this->playEveryGesture($prefix);

        foreach ($this->sent as $request) {
            foreach (array_filter([$request['key'], $request['prefix']]) as $path) {
                self::assertStringStartsWith($prefix.'dossiers/', $path, $request['operation']);
            }
            if (null !== $request['copySource']) {
                self::assertStringStartsWith(self::BUCKET.'/'.$prefix.'dossiers/', $request['copySource']);
            }
        }
        $operations = array_column($this->sent, 'operation');
        foreach (['PutObject', 'CreateMultipartUpload', 'UploadPart', 'CompleteMultipartUpload', 'GetObject', 'HeadObject', 'ListObjectsV2', 'CopyObject'] as $operation) {
            self::assertContains($operation, $operations, 'The scenario must make DossierFiles send '.$operation.'.');
        }
    }

    public function testEveryPublicGestureOfDossierFilesIsPlayed(): void
    {
        $methods = [];
        foreach ((new \ReflectionClass(DossierFiles::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ('__construct' !== $method->getName()) {
                $methods[] = $method->getName();
            }
        }
        sort($methods);
        $played = self::PLAYED;
        sort($played);

        self::assertSame($played, $methods, 'A new gesture of DossierFiles may send a new S3 operation: play it in this test, then check infra/terraform/iam.tf.');
    }

    public function testTheBucketActionsTakeTheBucketsArnAndTheObjectActionsAnObjectsArn(): void
    {
        $iam = $this->iamWithoutComments();

        // s3:ListBucket acts on the bucket: its ARN, no path - a path matches no bucket action.
        self::assertMatchesRegularExpression('/actions\s*=\s*\["s3:ListBucket"\]\s*resources\s*=\s*\[aws_s3_bucket\.documents\.arn\]/', $iam);
        // The object actions act on keys under the prefix.
        self::assertMatchesRegularExpression('/objects_arn\s*=\s*"\$\{aws_s3_bucket\.documents\.arn\}\/\$\{var\.prefix\}\*"/', $iam);
        self::assertMatchesRegularExpression('/actions\s*=\s*\[[^\]]*"s3:GetObject"[^\]]*\]\s*resources\s*=\s*\[local\.objects_arn\]/', $iam);
        self::assertStringNotContainsString('"s3:*"', $iam);
    }

    public function testTheStorageRunsOnTheFactorysClient(): void
    {
        $adapter = $this->storageOptions()['options'] ?? null;
        self::assertIsArray($adapter);
        self::assertSame('peyra.s3_client', $adapter['client'] ?? null);
    }

    private function playEveryGesture(string $prefix): void
    {
        $this->sent = [];
        $client = S3ClientFactory::create('eu-west-3', 'AKIAEXEMPLEPEYRA0000', 'cle-secrete-fictive', '');
        $client->getHandlerList()->setHandler(fn (CommandInterface $command, ?RequestInterface $request = null): PromiseInterface => $this->answer($command, $request, $prefix));

        $options = $this->storageOptions();
        $files = new DossierFiles(new Filesystem(new AwsS3V3Adapter($client, self::BUCKET, $prefix), [
            'visibility' => $options['visibility'] ?? null,
            'retain_visibility' => $options['retain_visibility'] ?? null,
        ]));

        self::assertSame('dossiers/7/', DossierFiles::prefix(7));
        $path = tempnam(sys_get_temp_dir(), 'peyra');
        self::assertIsString($path);
        try {
            file_put_contents($path, "date;libelle;montant\n2026-11-12;VIR;-2622,00\n");
            $files->storeUpload(7, 'releves', new UploadedFile($path, 'releve.csv', 'text/csv', null, true), UploadPolicy::platform()->restrictTo('csv'));
        } finally {
            @unlink($path);
        }
        $key = $files->storeContents(7, 'pieces', 'pdf', '%PDF-1.7 facture');
        // Above 16 MB (the upload policy allows 20) the SDK sends a file in parts.
        $files->storeContents(7, 'pieces', 'pdf', str_repeat('x', 17 * 1024 * 1024));
        self::assertSame($key, $files->owned(7, $key));
        $files->read(7, $key);
        $stream = $files->readStream(7, $key);
        fclose($stream);
        $files->exists(7, $key);
        $files->size(7, $key);
        self::assertSame(['dossiers/7/pieces/0123456789abcdef0123456789abcdef.pdf' => 'dossiers/8/pieces/0123456789abcdef0123456789abcdef.pdf'], $files->copyAll(7, 8));
        self::assertSame(1, $files->deleteAll(7));
    }

    private function answer(CommandInterface $command, ?RequestInterface $request, string $prefix): PromiseInterface
    {
        $string = static fn (string $name): ?string => \is_string($command[$name] ?? null) ? $command[$name] : null;
        $this->sent[] = [
            'operation' => $command->getName(),
            'key' => $string('Key'),
            'prefix' => $string('Prefix'),
            'copySource' => null !== $string('CopySource') ? ltrim(rawurldecode($string('CopySource')), '/') : null,
            'acl' => $string('ACL'),
            'aclHeader' => $request?->getHeaderLine('x-amz-acl') ?? '',
        ];
        $modified = new DateTimeResult('2026-01-15T10:00:00Z');

        return Create::promiseFor(new Result(match ($command->getName()) {
            'HeadObject' => ['ContentLength' => 16, 'ContentType' => 'application/pdf', 'LastModified' => $modified],
            'GetObject' => ['Body' => Utils::streamFor('%PDF-1.7 facture'), 'ContentLength' => 16],
            'ListObjectsV2' => ['IsTruncated' => false, 'Contents' => [
                ['Key' => $prefix.'dossiers/7/pieces/0123456789abcdef0123456789abcdef.pdf', 'Size' => 16, 'LastModified' => $modified],
            ]],
            'CreateMultipartUpload' => ['UploadId' => 'envoi-fictif'],
            'UploadPart' => ['ETag' => '"partie"'],
            default => ['ETag' => '"objet"'],
        }));
    }

    /** @return array<string, mixed> the `documents.s3` storage of config/packages/flysystem.yaml */
    private function storageOptions(): array
    {
        $node = Yaml::parseFile(self::ROOT.'/config/packages/flysystem.yaml');
        foreach (['flysystem', 'storages', 'documents.s3'] as $name) {
            self::assertIsArray($node);
            $node = $node[$name] ?? null;
        }
        $storage = $node;
        self::assertIsArray($storage);
        $options = [];
        foreach ($storage as $name => $value) {
            $options[(string) $name] = $value;
        }

        return $options;
    }

    /** @return list<string> the actions infra/terraform/iam.tf grants, sorted */
    private function grantedActions(): array
    {
        preg_match_all('/\bactions\s*=\s*\[([^\]]*)\]/', $this->iamWithoutComments(), $lists);
        $actions = [];
        foreach ($lists[1] as $list) {
            preg_match_all('/"([^"]+)"/', $list, $names);
            array_push($actions, ...$names[1]);
        }
        $actions = array_values(array_unique($actions));
        sort($actions);

        return $actions;
    }

    private function iamWithoutComments(): string
    {
        $iam = file_get_contents(self::ROOT.'/infra/terraform/iam.tf');
        self::assertIsString($iam);

        return (string) preg_replace('/^\s*#.*$/m', '', $iam);
    }
}
