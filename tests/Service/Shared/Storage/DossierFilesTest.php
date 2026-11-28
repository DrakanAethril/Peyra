<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Storage;

use App\Service\Shared\Storage\DossierFiles;
use App\Service\Shared\Storage\UploadPolicy;
use App\Service\Shared\Storage\UploadRefusal;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DossierFilesTest extends KernelTestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            exec('rm -rf '.escapeshellarg($this->directory));
        }
        parent::tearDown();
    }

    private function files(): DossierFiles
    {
        $this->directory = sys_get_temp_dir().'/peyra-files-'.bin2hex(random_bytes(6));

        return new DossierFiles(new Filesystem(new LocalFilesystemAdapter($this->directory)));
    }

    public function testFilesLiveUnderTheirDossierAndNeverCarryTheNameTyped(): void
    {
        $files = $this->files();

        $key = $files->storeContents(12, 'pieces', 'PDF', '%PDF-1.7 facture');

        self::assertMatchesRegularExpression('#^dossiers/12/pieces/[0-9a-f]{32}\.pdf$#', $key);
        self::assertSame('%PDF-1.7 facture', $files->read(12, $key));
        self::assertTrue($files->exists(12, $key));
        self::assertSame(16, $files->size(12, $key));
    }

    public function testAKeyIsReadOnlyWithItsOwnDossier(): void
    {
        $files = $this->files();
        $key = $files->storeContents(12, 'pieces', 'pdf', 'x');

        foreach ([[13, $key], [12, 'dossiers/12/../13/pieces/a.pdf'], [12, 'dossiers/1/pieces/a.pdf'], [1, 'dossiers/12/pieces/a.pdf']] as [$dossier, $foreign]) {
            try {
                $files->read($dossier, $foreign);
                self::fail(\sprintf('Dossier %d must not read %s.', $dossier, $foreign));
            } catch (\InvalidArgumentException) {
            }
        }
        self::addToAssertionCount(1);
    }

    public function testCategoriesAndExtensionsAreClosedShapes(): void
    {
        $files = $this->files();

        $this->expectException(\InvalidArgumentException::class);
        $files->storeContents(12, '../etc', 'pdf', 'x');
    }

    public function testCopyAllKeepsEachPlaceAndAnswersTheNewKeys(): void
    {
        $files = $this->files();
        $a = $files->storeContents(1, 'pieces', 'pdf', 'facture');
        $b = $files->storeContents(1, 'documents', 'pdf', 'bulletin');
        $other = $files->storeContents(10, 'pieces', 'pdf', 'autre dossier');

        $map = $files->copyAll(1, 7);

        self::assertCount(2, $map, 'The prefix of dossier 1 is not the one of dossier 10.');
        self::assertSame('dossiers/7/'.substr($a, \strlen('dossiers/1/')), $map[$a]);
        self::assertSame('facture', $files->read(7, $map[$a]));
        self::assertSame('bulletin', $files->read(7, $map[$b]));
        self::assertSame('facture', $files->read(1, $a), 'The original stays.');
        self::assertSame('autre dossier', $files->read(10, $other));
        self::assertSame([], $files->copyAll(3, 4), 'A dossier without files copies nothing.');
    }

    public function testAnUploadGoesThroughThePolicyThenToTheConfiguredStorage(): void
    {
        self::bootKernel();
        $files = self::getContainer()->get(DossierFiles::class);

        $path = tempnam(sys_get_temp_dir(), 'peyra');
        self::assertIsString($path);
        file_put_contents($path, "date;libelle;montant\n2026-11-12;VIR;-2622,00\n");
        $dossier = random_int(100000, 999999);

        $key = $files->storeUpload($dossier, 'releves', new UploadedFile($path, 'Relevé novembre.csv', 'text/csv', null, true), UploadPolicy::platform()->restrictTo('csv'));

        self::assertStringStartsWith('dossiers/'.$dossier.'/releves/', $key);
        self::assertStringEndsWith('.csv', $key);
        self::assertStringContainsString('VIR;-2622,00', $files->read($dossier, $key));
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $token = getenv('TEST_TOKEN');
        self::assertFileExists($kernel->getCacheDir().'/storage'.(\is_string($token) ? $token : '').'/'.$key, 'The test environment writes under the cache, never to the development volume.');

        $this->expectException(UploadRefusal::class);
        $files->storeUpload($dossier, 'pieces', new UploadedFile($path, 'releve.csv', 'text/csv', null, true), UploadPolicy::platform()->restrictTo('pdf'));
    }
}
