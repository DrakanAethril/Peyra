<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Storage;

use App\Service\Shared\Storage\UploadPolicy;
use App\Service\Shared\Storage\UploadRefusal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadPolicyTest extends TestCase
{
    public function testAcceptsWhatThePlatformKnows(): void
    {
        $policy = UploadPolicy::platform();

        self::assertNull($policy->refusal('Facture FA-2611-0457.PDF', 'application/pdf', 120_000));
        self::assertNull($policy->refusal('releve.csv', 'text/plain', 4_000), 'A genuine CSV sniffs as text/plain.');
        self::assertNull($policy->refusal('balance.xlsx', 'application/zip', 9_000));
        self::assertNull($policy->refusal('scan.jpeg', 'image/jpeg', 900_000));
    }

    public function testRefusesWithASentence(): void
    {
        $policy = UploadPolicy::platform();

        self::assertSame('Ce fichier n’a pas d’extension (.pdf, .jpg…) : renommez-le puis envoyez-le de nouveau.', $policy->refusal('facture', 'application/pdf', 10));
        self::assertSame('Ce fichier n’a pas d’extension (.pdf, .jpg…) : renommez-le puis envoyez-le de nouveau.', $policy->refusal('facture.', 'application/pdf', 10));
        self::assertSame('Ce type de fichier n’est jamais accepté.', $policy->refusal('facture.pdf.exe', 'application/pdf', 10));
        self::assertSame('Ce type de fichier n’est jamais accepté.', $policy->refusal('facture.exe.pdf', 'application/pdf', 10));
        self::assertSame('Ce fichier ne contient pas ce que son extension .png annonce.', $policy->refusal('photo.png', 'text/x-php', 10));
        self::assertSame('Ce fichier est vide.', $policy->refusal('vide.pdf', 'application/pdf', 0));
        self::assertSame('Ce fichier dépasse 20 Mo, la taille acceptée.', $policy->refusal('gros.pdf', 'application/pdf', 20 * 1024 * 1024 + 1));
        self::assertNull($policy->refusal('juste.pdf', 'application/pdf', 20 * 1024 * 1024));
    }

    public function testAFieldNarrowsAndNeverWidens(): void
    {
        $piece = UploadPolicy::platform()->restrictTo('PDF', 'jpg', 'png')->limitedTo(1536 * 1024);

        self::assertSame(['pdf', 'jpg', 'png'], $piece->extensions);
        self::assertSame('.pdf,.jpg,.png', $piece->accept());
        self::assertSame('Les fichiers .csv ne sont pas acceptés ici. Formats acceptés : .pdf, .jpg, .png.', $piece->refusal('releve.csv', 'text/plain', 10));
        self::assertSame('Ce fichier dépasse 1,5 Mo, la taille acceptée.', $piece->refusal('scan.pdf', 'application/pdf', 1536 * 1024 + 1));

        try {
            $piece->restrictTo('csv');
            self::fail('A narrowing cannot add an extension.');
        } catch (\InvalidArgumentException) {
        }
        try {
            UploadPolicy::platform()->restrictTo('svg');
            self::fail('The platform never accepts SVG.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $piece->limitedTo(UploadPolicy::PLATFORM_MAX_BYTES);
    }

    public function testReadsAnUploadedFileFromItsBytes(): void
    {
        $directory = sys_get_temp_dir().'/peyra-upload-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $path = $directory.'/x';
        file_put_contents($path, "<?php echo 'bonjour';\n");

        try {
            $disguised = new UploadedFile($path, 'facture.pdf', 'application/pdf', null, true);
            self::assertSame('Ce fichier ne contient pas ce que son extension .pdf annonce.', UploadPolicy::platform()->refusalOf($disguised));

            $csv = new UploadedFile($path, 'releve.csv', 'text/csv', null, true);
            file_put_contents($path, "date;libelle;montant\n2026-11-12;VIR SCIERIE;-2622,00\n");
            self::assertNull(UploadPolicy::platform()->refusalOf($csv));

            $tooBig = new UploadedFile($path, 'gros.pdf', 'application/pdf', \UPLOAD_ERR_INI_SIZE, true);
            self::assertSame('Ce fichier dépasse 20 Mo, la taille acceptée.', UploadPolicy::platform()->refusalOf($tooBig));

            $this->expectException(UploadRefusal::class);
            UploadPolicy::platform()->check($disguised);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
