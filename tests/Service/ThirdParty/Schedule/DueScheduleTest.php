<?php

declare(strict_types=1);

namespace App\Tests\Service\ThirdParty\Schedule;

use App\Enum\Audit\AuditAction;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\Audit\AuditEventRepository;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Edition\AgedBalanceEdition;
use App\Service\Accounting\Edition\EditionWindow;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\ThirdParty\DoubtfulReceivables;
use App\Service\ThirdParty\Schedule\DueItem;
use App\Service\ThirdParty\Schedule\DueSchedule;
use App\Tests\Support\BuildsLedger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The échéancier (step 2.7) in BuildsLedger's dossier (date 15/03/2026): Girard (C0019) invoiced
 * 1 200,00 on 10/01 (due on that day), 600,00 on 01/03 and 300,00 on 12/03, has paid 1 500,00 on
 * 05/03 - so 300,00 of the second invoice and the third are open; the Scierie (F0001) is owed 800,00
 * of a credit due 20/03. Totals agree with the aged balance; the doubtful marker is a customer's.
 */
final class DueScheduleTest extends KernelTestCase
{
    use BuildsLedger;

    public function testTheOpenLinesAreThoseOfTheAgedBalance(): void
    {
        $dossier = $this->ledgerDossier();
        $this->entry($dossier, 'VTE', '2026-01-10', 'FV-0001', ['411000/C0019 D 1200.00', '706000 C 1200.00'], true);
        $this->entry($dossier, 'VTE', '2026-03-01', 'FV-0002', ['411000/C0019 D 600.00', '706000 C 600.00'], true);
        $this->entry($dossier, 'VTE', '2026-03-12', 'FV-0003', ['411000/C0019 D 300.00', '706000 C 300.00']);
        $this->entry($dossier, 'BQ', '2026-03-05', 'VIR', ['512000 D 1500.00', '411000/C0019 C 1500.00'], true);
        $this->entry($dossier, 'ACH', '2026-03-10', 'FA-1', ['601000 D 800.00', '401000/F0001 C 800.00']);
        $schedule = self::getContainer()->get(DueSchedule::class);
        $at = new \DateTimeImmutable('2026-03-15');

        $customers = $schedule->read($dossier, ThirdPartyKind::Customer, $at);
        // Due on the day of the invoice (no due date on the lines): FV-0002 14 days late, FV-0003 3.
        self::assertSame([['FV-0002', 60_000, 30_000, 14], ['FV-0003', 30_000, 30_000, 3]], array_map(static fn (DueItem $item): array => [$item->line->getEntry()->getDocument(), $item->lineAmount->cents, $item->open->cents, $item->daysLate], $customers->items));
        self::assertSame([60_000, 60_000, 0], [$customers->total()->cents, $customers->overdue()->cents, $customers->notDue()->cents]);

        $suppliers = $schedule->read($dossier, ThirdPartyKind::Supplier, $at);
        self::assertSame([['FA-1', 80_000, 5]], array_map(static fn (DueItem $item): array => [$item->line->getEntry()->getDocument(), $item->open->cents, $item->daysLate], $suppliers->items));

        // The aged balance reads the same totals.
        $aged = self::getContainer()->get(AgedBalanceEdition::class)->read($dossier, EditionWindow::upTo('2026-03-15', self::getContainer()->get(ExerciseRepository::class)->findByDossier($dossier), $dossier->getDossierDate()), EntryScope::WithDrafts, ThirdPartyKind::Customer);
        self::assertSame($customers->total()->cents, $aged->total->total->cents);

        $thirdParties = self::getContainer()->get(ThirdPartyRepository::class);
        $receivables = self::getContainer()->get(DoubtfulReceivables::class);
        $girard = $thirdParties->findOneByCode($dossier, 'C0019') ?? throw new \LogicException('C0019');
        self::assertTrue($receivables->mark($girard, true, 'test'));
        self::assertFalse($receivables->mark($girard, true, 'test'), 'Marked once.');
        self::assertTrue($girard->isDoubtful());
        self::assertCount(1, self::getContainer()->get(AuditEventRepository::class)->findBy(['action' => AuditAction::ThirdPartyDoubtful]));
        $this->expectException(\LogicException::class);
        $receivables->mark($thirdParties->findOneByCode($dossier, 'F0001') ?? throw new \LogicException('F0001'), true, 'test');
    }
}
