<?php

declare(strict_types=1);

namespace App\Service\Shared\Storage;

use Aws\CommandInterface;
use Aws\Middleware;
use Aws\S3\S3Client;

/**
 * Builds the client of the `documents.s3` storage (config/packages/flysystem.yaml) from the
 * S3_* variables. Only built when PEYRA_STORAGE=s3: development and test never construct it.
 *
 * Empty keys leave the SDK to its own credential chain (an instance role, say) rather than send
 * empty ones; an endpoint names an S3-compatible service and switches to path-style addresses,
 * which those services expect.
 *
 * **The client never sends an ACL.** Flysystem's adapter puts one on every upload and every copy
 * (`private`), and S3 answers it two ways, both wrong for Peyra: an ACL header needs
 * `s3:PutObjectAcl`, and the bucket of infra/terraform/ has ACLs disabled (« Bucket owner
 * enforced »), which refuses every ACL but `bucket-owner-full-control`. Access to Peyra's files is
 * the IAM policy alone; S3BucketPolicyTest holds it against what this client sends.
 */
final class S3ClientFactory
{
    /** The name of the step, in the client's handler list, that takes the ACL off a command. */
    public const string NO_ACL = 'peyra.no_acl';

    public static function create(string $region, string $accessKeyId, string $secretAccessKey, string $endpoint): S3Client
    {
        $options = [
            'version' => '2006-03-01',
            'region' => '' !== $region ? $region : 'eu-west-3',
        ];
        if ('' !== $accessKeyId && '' !== $secretAccessKey) {
            $options['credentials'] = ['key' => $accessKeyId, 'secret' => $secretAccessKey];
        }
        if ('' !== $endpoint) {
            $options['endpoint'] = $endpoint;
            $options['use_path_style_endpoint'] = true;
        }

        $client = new S3Client($options);
        // At the « init » step, before the command is validated and serialised into a request:
        // PutObject, CopyObject and CreateMultipartUpload then carry no x-amz-acl header at all.
        $client->getHandlerList()->appendInit(Middleware::mapCommand(static function (CommandInterface $command): CommandInterface {
            unset($command['ACL']);

            return $command;
        }), self::NO_ACL);

        return $client;
    }
}
