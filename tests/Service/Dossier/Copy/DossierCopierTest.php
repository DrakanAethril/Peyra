<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Copy;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Identity\User;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Accounting\EntryState;
use App\Enum\Banking\BillRemittance;
use App\Enum\Banking\PaymentDirection;
use App\Enum\Document\DocumentType;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DossierKind;
use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\ThirdParty\PaymentMethod;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\Payroll\EmployeeRepository;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Banking\Payment\PaymentRequest;
use App\Service\Banking\Payment\Payments;
use App\Service\Document\Documents;
use App\Service\Dossier\Copy\CopyPlan;
use App\Service\Dossier\Copy\DossierCopier;
use App\Service\Dossier\Copy\DossierEraser;
use App\Service\Dossier\Demo\DemoInstaller;
use App\Service\Dossier\DossierContext;
use App\Service\Dossier\DossierDates;
use App\Service\Inventory\Stock\StockCounts;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipForcing;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipElements;
use App\Service\Regulatory\RegulatorySync;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Money;
use App\Service\Shared\Storage\DossierFiles;
use App\Tests\Support\CanonicalDossierExport;
use App\Tests\Support\DossierFixtures;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The completeness test of the dossier copier (README of the plan, § 8): the demonstration
 * dossier, given what it lacks for every table to hold rows (a pending entry rewriting a draft, a
 * reversal, a moved date, a pièce and its file), copied, then both copies compared through their canonical
 * exports. A new DossierOwned entity fails it until the source holds rows of it and the copy
 * reproduces them; a column holding a file's key without its mark fails it too.
 */
final class DossierCopierTest extends KernelTestCase
{
    private Dossier $source;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $at = new \DateTimeImmutable('2026-11-28 09:00');
        // The demonstration's DPAE reads the regulatory delays (step 4.2b), as production syncs them before installing it.
        self::getContainer()->get(RegulatorySync::class)->run(new \DateTimeImmutable('2026-11-28 10:00'));
        $installer = self::getContainer()->get(DemoInstaller::class);
        $installer->installTemplate('test', $at);
        $this->source = $installer->findTemplate() ?? throw new \LogicException('The demonstration is installed.');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = new User('uid:copieur', 'prof.copieur', 'Prof Copieur', AccountKind::Teacher, EntryBasis::TeacherGroup, ['teacher', 'cg-1'], $at);
        $entityManager->persist($this->teacher);
        $entityManager->flush();

        self::getContainer()->get(DossierContext::class)->within($this->source, function () use ($entityManager, $at): void {
            $journals = self::getContainer()->get(JournalRepository::class);
            $march = self::getContainer()->get(PeriodRepository::class)->findAt($this->source, new \DateTimeImmutable('2026-03-15'));
            $purchases = $journals->findOneByCode($this->source, 'ACH');
            self::assertNotNull($march);
            self::assertNotNull($purchases);
            $entries = self::getContainer()->get(EntryRepository::class);
            $draft = $entries->findDrafts($purchases, $march)[0] ?? throw new \LogicException('The demonstration has drafts in ACH.');
            // A person's pending work: the draft being rewritten, and a new entry left unbalanced.
            self::getContainer()->get(PendingEntries::class)->keep($purchases, $march, $this->teacher, [
                new GridGroup('a', $draft->getId(), '2026-03-20', $draft->getDocument(), [new GridLine('606100', '', 'Fournitures', 1000), new GridLine('401000', '', 'Fournitures', 0, 1000)]),
                new GridGroup('b', null, '2026-03-21', 'F-NEW', [new GridLine('606100', '', 'Laissée', 500)]),
            ], null, $at);
            $entityManager->flush();
            // A reversal: an entry pointing at another of its table.
            $validated = array_values(array_filter($entries->findInJournalPeriod($purchases, $march), static fn (Entry $entry): bool => EntryState::Validated === $entry->getState()))[0];
            self::getContainer()->get(Ledger::class)->reverse($validated, new \DateTimeImmutable('2026-03-31'), $this->teacher);
            // The demonstration's payslips are validated - frozen - and their months closed up to March (review 4.7a);
            // April's, the month paid, with its elements and a value forced (steps 4.4a and 4.4b), validated too.
            $lefevre = self::getContainer()->get(EmployeeRepository::class)->findOneByNumber($this->source, 'S003') ?? throw new \LogicException('The demonstration has S003.');
            $book = self::getContainer()->get(PayslipBook::class);
            $payslip = $book->record($lefevre, $book->month($this->source), new PayslipElements(rates: ['1010' => BigDecimal::of('3')]), new PayslipAdjustments(forced: ['1000' => new PayslipForcing(base: BigDecimal::of('140'))]));
            $entityManager->flush();
            self::assertNull($book->validate($payslip, $this->teacher));
            $entityManager->flush();
            // A physical inventory validated (step 2.5b): its gap an adjustment that names it.
            $counts = self::getContainer()->get(StockCounts::class);
            $count = $counts->open($this->source, new \DateTimeImmutable('2026-03-31'), 'Dépôt principal', $this->teacher);
            $lines = $count->getLines();
            $lines[0] = $lines[0]->withCount($counts->theoretical($count)[0]->plus(1), 'Lot retrouvé');
            $counts->save($count, $lines, $this->teacher);
            $counts->validate($count, Assistance::at(AssistanceLevel::Warnings), $this->teacher);
            // Payments (step 2.6b): a cheque settling an invoice, remitted; a bill drawn, remitted for collection.
            $payments = self::getContainer()->get(Payments::class);
            $invoice = self::getContainer()->get(EntryRepository::class)->findByDocumentReference($this->source, 'FV-2026-0003')[0] ?? throw new \LogicException('The demonstration has FV-2026-0003.');
            $cheque = $payments->record($this->source, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Cheque, 'C0003', 'BQ1', new \DateTimeImmutable('2026-03-17'), '4471230', Money::cents(100_000), null, [(int) $invoice->getId()]), $this->teacher);
            $bank = $journals->findOneByCode($this->source, 'BQ1') ?? throw new \LogicException('BQ1');
            $payments->deposit($bank, [(int) $cheque->getId()], new \DateTimeImmutable('2026-03-18'), $this->teacher);
            $bill = $payments->record($this->source, new PaymentRequest(PaymentDirection::Receipt, PaymentMethod::Bill, 'C0003', 'OD', new \DateTimeImmutable('2026-03-19'), 'LCR-0001', Money::cents(50_000), new \DateTimeImmutable('2026-04-30'), [(int) $invoice->getId()]), $this->teacher);
            $payments->remit($bill, BillRemittance::Collection, $bank, new \DateTimeImmutable('2026-03-20'), Money::zero(), Money::zero(), Money::zero(), $this->teacher);
        });
        self::getContainer()->get(DossierDates::class)->move($this->source, new \DateTimeImmutable('2026-02-28'), $this->teacher, $at);
        $entityManager->flush();
        // A pièce: a row of its own and a file whose key the copy rewrites; and its doublon, which names it.
        $path = tempnam(sys_get_temp_dir(), 'piece');
        \assert(\is_string($path));
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        self::getContainer()->get(DossierContext::class)->within($this->source, function () use ($entityManager, $path): void {
            $documents = self::getContainer()->get(Documents::class);
            $first = $documents->deposit($this->source, new UploadedFile($path, 'FA-2603-0112.pdf', null, null, true), DocumentType::PurchaseInvoice, $this->teacher);
            $entityManager->flush();
            $documents->secondArrival($first, $this->teacher);
            $entityManager->flush();
        });
    }

    public function testASnapshotReproducesEveryTableOfTheDossier(): void
    {
        $copy = $this->emptyDossier(DossierKind::Snapshot, null);

        $report = self::getContainer()->get(DossierCopier::class)->copy($this->source, $copy, CopyKind::Snapshot);

        $export = $this->export();
        self::assertSame($export->of($this->source), $export->of($copy));
        self::assertSame(1, $report->rowsOf('pending_entry'));
        self::assertSame(2, $report->files, 'The pièce’s file and its doublon’s.');
        self::assertSame($this->source->getCompany()->getSiret(), $copy->getCompany()->getSiret());
        self::assertSame('pcg-2026', $copy->getChartCode());
        // Their files, under its own prefix.
        $files = self::getContainer()->get(DossierFiles::class);
        self::assertSame(2, $files->deleteAll((int) $copy->getId()));
    }

    public function testATemplateCopyLeavesThePendingWorkBehind(): void
    {
        $copy = $this->emptyDossier(DossierKind::Template, $this->teacher);

        $report = self::getContainer()->get(DossierCopier::class)->copy($this->source, $copy, CopyKind::Template);

        $export = $this->export();
        $expected = $export->of($this->source, CopyKind::Template);
        self::assertArrayNotHasKey('pending_entry', $expected);
        self::assertSame($expected, $export->of($copy, CopyKind::Template));
        self::assertSame(0, $report->rowsOf('pending_entry'));
        self::assertSame([], $export->of($copy)['pending_entry']);
        self::assertSame(0, $report->rowsOf('dossier_date_change'), 'L’historique de la date n’est jamais copié.');
    }

    public function testTheSourceHoldsRowsOfEveryTableTheCopierKnows(): void
    {
        $plan = self::getContainer()->get(CopyPlan::class);
        $export = $this->export()->of($this->source);
        $connection = self::getContainer()->get(Connection::class);

        foreach ($plan->tables(CopyKind::Snapshot) as $table) {
            self::assertNotEmpty($export[$table->table], $table->table.' : ajoutez-en au dossier de démonstration ou à ce test, que la copie en soit éprouvée.');
            foreach ($table->selfReferences as $column) {
                self::assertGreaterThan(0, self::rowCount($connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE dossier_id = ? AND %s IS NOT NULL', $table->table, $column), [$this->source->getId()])), $table->table.'.'.$column.' : aucune ligne ne s’en sert.');
            }
        }
        foreach ([...array_keys(CopyPlan::NOT_COPIED), ...CopyPlan::PENDING_WORK] as $entity) {
            self::assertTrue((new \ReflectionClass($entity))->implementsInterface(DossierOwned::class), $entity);
        }
        self::assertCount(\count($plan->ownedTables()), [...$plan->tables(CopyKind::Snapshot), ...array_keys(CopyPlan::NOT_COPIED)]);
    }

    public function testNoColumnHoldsAFileKeyWithoutItsMark(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $prefix = DossierFiles::prefix((int) $this->source->getId());
        $unmarked = [];
        foreach (self::getContainer()->get(CopyPlan::class)->tables(CopyKind::Snapshot) as $table) {
            foreach ($table->columns as $column) {
                if (!$table->isPlain($column)) {
                    continue;
                }
                $count = self::rowCount($connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE dossier_id = ? AND CAST(%s AS CHAR) LIKE ?', $table->table, $connection->quoteSingleIdentifier($column)), [$this->source->getId(), $prefix.'%']));
                if ($count > 0) {
                    $unmarked[] = $table->table.'.'.$column;
                }
            }
        }

        self::assertSame([], $unmarked, 'Ces colonnes tiennent la clé d’un fichier du dossier : marquez-les #[DossierFileKey].');
    }

    public function testACopyLandsOnlyInAnEmptyDossierAndTheEraserEmptiesIt(): void
    {
        $copier = self::getContainer()->get(DossierCopier::class);
        $eraser = self::getContainer()->get(DossierEraser::class);
        $export = $this->export();
        $copy = $this->emptyDossier(DossierKind::Snapshot, null);
        $copier->copy($this->source, $copy, CopyKind::Snapshot);

        try {
            $copier->copy($this->source, $copy, CopyKind::Snapshot);
            self::fail('Une copie ne se pose pas sur un dossier qui a déjà un contenu.');
        } catch (\LogicException) {
        }

        $deleted = $eraser->eraseContent($copy);
        self::assertGreaterThan(0, $deleted['entry_line']);
        self::assertSame(array_map(static fn (): array => [], $export->of($copy)), $export->of($copy));
        // The way back from a restore point: erased, then copied again.
        $copier->copy($this->source, $copy, CopyKind::Snapshot);
        self::assertSame($export->of($this->source), $export->of($copy));
    }

    public function testDeletingADossierDeletesItsRowsAndLeavesTheSourceAsItWas(): void
    {
        $export = $this->export();
        $before = $export->of($this->source);
        $copy = $this->emptyDossier(DossierKind::Template, $this->teacher);
        self::getContainer()->get(DossierCopier::class)->copy($this->source, $copy, CopyKind::Template);
        $id = (int) $copy->getId();
        self::getContainer()->get(DossierDates::class)->move($copy, new \DateTimeImmutable('2026-03-31'), $this->teacher, new \DateTimeImmutable('2026-11-28 10:00'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::getContainer()->get(DossierEraser::class)->deleteDossier($copy);

        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, self::rowCount($connection->fetchOne('SELECT COUNT(*) FROM dossier WHERE id = ?', [$id])));
        foreach (self::getContainer()->get(CopyPlan::class)->ownedTables() as $table) {
            self::assertSame(0, self::rowCount($connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE dossier_id = ?', $table->table), [$id])), $table->table);
        }
        self::assertFalse(self::getContainer()->get(DossierFiles::class)->deleteAll($id) > 0);
        self::assertSame($before, $export->of($this->source));
    }

    private static function rowCount(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : throw new \UnexpectedValueException('A count.');
    }

    private function export(): CanonicalDossierExport
    {
        return new CanonicalDossierExport(self::getContainer()->get(Connection::class), self::getContainer()->get(CopyPlan::class), self::getContainer()->get(DossierCopier::class));
    }

    private function emptyDossier(DossierKind $kind, ?User $owner): Dossier
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $dossier = new Dossier($kind, DossierFixtures::company('Autre société'), new \DateTimeImmutable('2026-01-31'), $owner, null, new \DateTimeImmutable('2026-11-28 09:30'), $this->teacher);
        $entityManager->persist($dossier);
        $entityManager->flush();

        return $dossier;
    }
}
