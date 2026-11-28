<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Fec\Import;

use App\Entity\Accounting\FecImportJob;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\FecImportStage;
use App\Enum\Accounting\FecImportState;
use App\Enum\Accounting\JournalType;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\FecImportJobRepository;
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
 * The import of a FEC into a new template (steps 1.9b and 1.10b), on the demonstration's: launched,
 * then written pass after pass, its FEC exported again gives back the very same lines - accounts,
 * third parties, journals, entries in Peyra's numbering, lettering -, the template hidden until the
 * last pass; a file with an anomaly, even one only a door finds, writes nothing.
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
        // The install clears the entity manager: the teacher is read again.
        $this->teacher = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->teacher->getId()) ?? throw new \LogicException('Teacher.');
        $fec = $this->fecOf($demo);
        self::assertTrue($fec->report->isConform());

        $draft = $this->draft($fec->content, $fec->name);
        $import = self::getContainer()->get(FecImport::class);
        $job = $import->launch($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00'));

        self::assertSame([], $draft->findings);
        self::assertNotNull($job);
        $jobId = (int) $job->getId();
        $template = $job->getTemplate();
        self::assertNotNull($template);
        $templateId = (int) $template->getId();
        self::assertTrue($template->isImporting());
        self::assertSame(FecImportStage::Setup, $job->getStage());
        self::assertSame($fec->entries, $job->getEntries());
        self::assertSame(0, $job->getZeroLines());
        self::assertSame(0, $job->getLettersLeft());
        self::assertSame(9, $job->getLetterings(), 'The Scierie A to E, SCI Vasseur and Loire Bail A and B.');
        self::assertNotContains($templateId, $this->templatesOfTheTeacher(), 'Hidden from the library while it fills.');

        // Forty entries a pass: one pass to set up, then as many to draft as to validate, one to letter.
        $passes = 1;
        while ($import->pass($jobId, new \DateTimeImmutable('2026-11-28 10:01'), 40)) {
            ++$passes;
            $job = self::getContainer()->get(FecImportJobRepository::class)->find($jobId);
            self::assertTrue($job?->getTemplate()?->isImporting());
        }
        self::assertSame(1 + 2 * (int) ceil($fec->entries / 40) + 1, $passes);
        self::assertGreaterThan(200, $fec->entries);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $job = $entityManager->find(FecImportJob::class, $jobId) ?? throw new \LogicException('The import.');
        self::assertSame(FecImportState::Done, $job->getState());
        self::assertSame(100, $job->percent());
        $template = $job->getTemplate() ?? throw new \LogicException('Its template.');
        self::assertFalse($template->isImporting());
        self::assertSame(DossierKind::Template, $template->getKind());
        self::assertSame('Vasseur, depuis son FEC', $template->getLibraryTitle());
        self::assertContains($templateId, $this->templatesOfTheTeacher());
        self::assertFalse($import->pass($jobId, new \DateTimeImmutable('2026-11-28 10:02')), 'A finished import has no pass left.');

        $again = $this->fecOf($template);
        self::assertTrue($again->report->isConform());
        self::assertSame($fec->content, $again->content);

        self::getContainer()->get(DossierContext::class)->within($template, function () use ($template): void {
            $journals = self::getContainer()->get(JournalRepository::class);
            self::assertSame(JournalType::Opening, $journals->findOneByCode($template, 'AN')?->getType());
            self::assertSame(JournalType::Purchases, $journals->findOneByCode($template, 'ACH')?->getType());
            $bank = $journals->findOneByCode($template, 'BQ2');
            self::assertNotNull($bank);
            self::assertSame(JournalType::Bank, $bank->getType());
            self::assertSame('512200', $bank->getCounterpartAccount()?->getNumber());
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
        self::assertNull(self::getContainer()->get(FecImport::class)->launch($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));

        self::assertCount(1, $draft->findings);
        self::assertSame(5, $draft->findings[0]->line);
        self::assertSame('CompAuxNum', $draft->findings[0]->zone);
        self::assertCount($before, $dossiers->findAll());
    }

    public function testAFileIsAskedForAndAFileOutsideTheExerciseIsRefused(): void
    {
        $draft = $this->draft(null, null);
        self::assertNull(self::getContainer()->get(FecImport::class)->launch($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertSame('Choisissez le fichier FEC à importer.', $draft->fileError);

        $header = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";
        $draft = $this->draft($header."\nOD\tOD\t1\t20250105\t512000\tBanque\t\t\tX\t20250105\tVirement\t10,00\t0,00\t\t\t20250105\t\t\nOD\tOD\t1\t20250105\t580000\tVirements\t\t\tX\t20250105\tVirement\t0,00\t10,00\t\t\t20250105\t\t\n", 'FEC.txt');
        self::assertNull(self::getContainer()->get(FecImport::class)->launch($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertSame(['EcritureDate', 'EcritureDate'], array_map(static fn ($finding): ?string => $finding->zone, $draft->findings));
    }

    public function testWhatOnlyTheDoorsRefuseIsRefusedBeforeAnythingIsWritten(): void
    {
        $dossiers = self::getContainer()->get(DossierRepository::class);
        $before = \count($dossiers->findAll());
        $header = "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise";
        // A third party kept on a depreciation account (491): read as one of « other » kind, which
        // ThirdPartyRules refuses there.
        $bytes = implode("\r\n", [
            $header,
            "OD\tOD\t1\t20260131\t681740\tDotations\t\t\tDEP1\t20260131\tDépréciation\t80,00\t0,00\t\t\t20260131\t\t",
            "OD\tOD\t1\t20260131\t491000\tDépréciations\tDOUT\tClient douteux\tDEP1\t20260131\tDépréciation\t0,00\t80,00\t\t\t20260131\t\t",
        ])."\r\n";

        $draft = $this->draft($bytes, 'FEC.txt');
        self::assertNull(self::getContainer()->get(FecImport::class)->launch($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));

        self::assertCount(1, $draft->findings, implode(' | ', array_map(static fn ($f): string => $f->line.' '.$f->zone.' '.$f->message, $draft->findings)));
        self::assertSame(3, $draft->findings[0]->line);
        self::assertSame('CompAuxNum', $draft->findings[0]->zone);
        self::assertCount($before, $dossiers->findAll());
        self::assertSame([], self::getContainer()->get(FecImportJobRepository::class)->findAll());
    }

    public function testAFecOfMoreThanTenThousandEntriesIsRefusedWhole(): void
    {
        $lines = ["JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise"];
        for ($i = 1; $i <= FecImport::MAX_ENTRIES + 1; ++$i) {
            $lines[] = "OD\tOD\t$i\t20260105\t512000\tBanque\t\t\tX$i\t20260105\tVirement\t1,00\t0,00\t\t\t20260105\t\t";
            $lines[] = "OD\tOD\t$i\t20260105\t580000\tVirements\t\t\tX$i\t20260105\tVirement\t0,00\t1,00\t\t\t20260105\t\t";
        }
        $draft = $this->draft(implode("\n", $lines), 'FEC.txt');

        self::assertNull(self::getContainer()->get(FecImport::class)->launch($draft, $this->teacher, new \DateTimeImmutable('2026-11-28 10:00')));
        self::assertCount(1, $draft->findings);
        self::assertStringContainsString('celui-ci en compte 10'."\u{202F}".'001', $draft->findings[0]->message);
    }

    /** @return list<int> */
    private function templatesOfTheTeacher(): array
    {
        $teacher = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->teacher->getId()) ?? throw new \LogicException('Teacher.');

        return array_map(static fn (Dossier $dossier): int => (int) $dossier->getId(), self::getContainer()->get(DossierRepository::class)->findTemplatesOf($teacher));
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
            'exerciseStart' => '2026-01-01', 'exerciseEnd' => '2026-12-31', 'dossierDate' => '2026-03-31', 'chart' => 'pcg-2026',
        ]));
        $draft->bytes = $bytes;
        $draft->fileName = $name;

        return $draft;
    }
}
