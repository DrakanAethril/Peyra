<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Fec\Import;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Fec\FecExport;
use App\Service\Accounting\Fec\FecFile;
use App\Service\Accounting\Fec\Import\FecImport;
use App\Service\Accounting\Fec\Import\FecImportDraft;
use App\Service\Dossier\Demo\DemoInstaller;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\Library\EmptyTemplateDraft;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The import of a FEC into a new template (step 1.9b), on the demonstration's: its FEC imported
 * then exported again gives back the very same lines - accounts, third parties, journals, entries
 * in Peyra's numbering, lettering -; a file with an anomaly writes nothing.
 */
final class FecImportTest extends KernelTestCase
{
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $at = new \DateTimeImmutable('2026-11-28 09:00');
        $this->teacher = new User('uid:importeur', 'prof.importeur', 'Prof Importeur', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], $at);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->teacher);
        $entityManager->flush();
    }

    public function testTheDemonstrationsFecImportedThenExportedGivesTheSameLines(): void
    {
        $installer = self::getContainer()->get(DemoInstaller::class);
        $installer->installTemplate('test', new \DateTimeImmutable('2026-11-28 09:00'));
        $demo = $installer->findTemplate() ?? throw new \LogicException('The demonstration is installed.');
        $fec = $this->fecOf($demo);
        self::assertTrue($fec->report->isConform());

        $draft = $this->draft($fec->content, $fec->name);
        $result = self::getContainer()->get(FecImport::class)->import($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00'));

        self::assertSame([], $draft->findings);
        self::assertNotNull($result);
        $template = $result->template;
        self::assertSame(DossierKind::Template, $template->getKind());
        self::assertSame('Vasseur, depuis son FEC', $template->getLibraryTitle());
        self::assertSame($fec->entries, $result->entries);
        self::assertSame(0, $result->zeroLines);
        self::assertSame(0, $result->lettersLeft);
        self::assertSame(4, $result->letterings);

        $again = $this->fecOf($template);
        self::assertTrue($again->report->isConform());
        self::assertSame($fec->content, $again->content);

        self::getContainer()->get(DossierContext::class)->within($template, function () use ($template): void {
            $journals = self::getContainer()->get(JournalRepository::class);
            self::assertSame(JournalType::Opening, $journals->findOneByCode($template, 'AN')?->getType());
            self::assertSame(JournalType::Purchases, $journals->findOneByCode($template, 'ACH')?->getType());
            self::assertSame('512200', $journals->findOneByCode($template, 'BQ2')?->getCounterpartAccount()?->getNumber());
            self::assertSame(JournalType::Bank, $journals->findOneByCode($template, 'BQ2')?->getType());
            $supplier = self::getContainer()->get(ThirdPartyRepository::class)->findOneBy(['auxiliaryCode' => '401SCIE']);
            self::assertSame('401000', $supplier?->getAccount()?->getNumber());
            self::assertTrue(self::getContainer()->get(AccountRepository::class)->findOneByNumber($template, '401000')?->isCollective());
        });
    }

    public function testAFileWithAnAnomalyWritesNothingAndSaysWhichLine(): void
    {
        $dossiers = self::getContainer()->get(DossierRepository::class);
        $before = \count($dossiers->findAll());
        $header = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";
        $bytes = implode("\r\n", [
            $header,
            "ACH\tAchats\t1\t20260108\t607\tAchats\t\t\tFA1\t20260108\tFacture\t100,00\t0,00\t\t\t20260131\t\t",
            "ACH\tAchats\t1\t20260108\t401\tFournisseurs\tSCIE\tScierie\tFA1\t20260108\tFacture\t0,00\t100,00\t\t\t20260131\t\t",
            "ACH\tAchats\t2\t20260110\t607\tAchats\t\t\tFA2\t20260110\tFacture\t50,00\t0,00\t\t\t20260131\t\t",
            "ACH\tAchats\t2\t20260110\t401\tFournisseurs\t\t\tFA2\t20260110\tFacture\t0,00\t50,00\t\t\t20260131\t\t",
        ])."\r\n";

        $draft = $this->draft($bytes, 'FEC.txt');
        self::assertNull(self::getContainer()->get(FecImport::class)->import($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));

        self::assertCount(1, $draft->findings);
        self::assertSame(5, $draft->findings[0]->line);
        self::assertSame('CompAuxNum', $draft->findings[0]->zone);
        self::assertSame($before, \count($dossiers->findAll()));
    }

    public function testAFileIsAskedForAndAFileOutsideTheExerciseIsRefused(): void
    {
        $draft = $this->draft(null, null);
        self::assertNull(self::getContainer()->get(FecImport::class)->import($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertSame('Choisissez le fichier FEC à importer.', $draft->fileError);

        $header = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";
        $draft = $this->draft($header."\nOD\tOD\t1\t20250105\t512000\tBanque\t\t\tX\t20250105\tVirement\t10,00\t0,00\t\t\t20250105\t\t\nOD\tOD\t1\t20250105\t580000\tVirements\t\t\tX\t20250105\tVirement\t0,00\t10,00\t\t\t20250105\t\t\n", 'FEC.txt');
        self::assertNull(self::getContainer()->get(FecImport::class)->import($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertSame(['EcritureDate', 'EcritureDate'], array_map(static fn ($finding): ?string => $finding->zone, $draft->findings));
    }

    public function testAFecOfMoreThanAThousandEntriesIsRefusedWhole(): void
    {
        $lines = ["JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise"];
        for ($i = 1; $i <= FecImport::MAX_ENTRIES + 1; ++$i) {
            $lines[] = "OD\tOD\t$i\t20260105\t512000\tBanque\t\t\tX$i\t20260105\tVirement\t1,00\t0,00\t\t\t20260105\t\t";
            $lines[] = "OD\tOD\t$i\t20260105\t580000\tVirements\t\t\tX$i\t20260105\tVirement\t0,00\t1,00\t\t\t20260105\t\t";
        }
        $draft = $this->draft(implode("\n", $lines), 'FEC.txt');

        self::assertNull(self::getContainer()->get(FecImport::class)->import($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertCount(1, $draft->findings);
        self::assertStringContainsString('celui-ci en compte 1'."\u{202F}".'001', $draft->findings[0]->message);
    }

    private function fecOf(Dossier $dossier): FecFile
    {
        return self::getContainer()->get(DossierContext::class)->within($dossier, function () use ($dossier): FecFile {
            $exercise = self::getContainer()->get(ExerciseRepository::class)->findByDossier($dossier)[0] ?? throw new \LogicException('An exercise.');

            return self::getContainer()->get(FecExport::class)->of($dossier, $exercise);
        });
    }

    private function draft(?string $bytes, ?string $name): FecImportDraft
    {
        $draft = new FecImportDraft();
        $draft->template = EmptyTemplateDraft::fromRequest(new Request(request: [
            'title' => 'Vasseur, depuis son FEC',
            'denomination' => 'Menuiserie Vasseur SARL', 'legalForm' => 'sarl', 'capital' => '5000000', 'siret' => '99941273600018',
            'activity' => 'Menuiserie', 'street' => '12 rue des Tilleuls', 'postalCode' => '42600', 'city' => 'Montbrison',
            'vatRegime' => 'real_normal_monthly', 'profitTaxRegime' => 'is_real_normal',
            'exerciseStart' => '2026-01-01', 'exerciseEnd' => '2026-12-31', 'dossierDate' => '2026-01-31', 'chart' => 'pcg-2026',
        ]));
        $draft->bytes = $bytes;
        $draft->fileName = $name;

        return $draft;
    }
}
