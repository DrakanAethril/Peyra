<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Enum\Sales\SalesDocumentType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\VatLine;
use App\Service\Sales\SalesComputation;
use App\Service\Sales\SalesDeposit;
use App\Service\Sales\SalesEntries;
use App\Service\Sales\SalesLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The entry of an invoicing document (step 2.3b), worked out by hand for each case: Girard (C0019,
 * 411GIRA), journal VTE.
 */
final class SalesEntriesTest extends TestCase
{
    /**
     * FV-2026-0001: 1 000,00 and 200,00 of products on 701000, port 85,00 on 708500, 20 %, escompte
     * 2 %: net commercial 1 285,00, escompte 25,70, net financier 1 259,30, VAT 251,86, TTC
     * 1 511,16. Debits 1 511,16 + 25,70 = 1 536,86 = credits 1 200,00 + 85,00 + 251,86.
     */
    public function testAnInvoiceWithACashDiscount(): void
    {
        $lines = [self::line('1000.00'), self::line('85.00'), self::line('200.00')];
        $totals = SalesComputation::of($lines, BigDecimal::of('2'));
        $revenue = [
            ['account' => '701000', 'amount' => $totals->lineAmounts[0]],
            ['account' => '708500', 'amount' => $totals->lineAmounts[1]],
            ['account' => '701000', 'amount' => $totals->lineAmounts[2]],
        ];
        $entry = SalesEntries::propose(SalesDocumentType::Invoice, self::girard(), 'C0019', 'FV-2026-0001', new \DateTimeImmutable('2026-03-12'), new \DateTimeImmutable('2026-04-11'), $revenue, $totals, false, 'VTE');

        self::assertSame([['411GIRA', '', 151_116, 0, '2026-04-11'], ['665000', '', 2_570, 0, null], ['701000', '', 0, 120_000, null], ['708500', '', 0, 8_500, null], ['445710', '', 0, 25_186, null]], self::rows($entry));
        self::assertSame(['VTE', '2026-03-12', 'FV-2026-0001', 'Girard, Jean et Anne FV-2026-0001'], [$entry->journal, $entry->date, $entry->document, $entry->label]);
        self::assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    /** AC-2026-0001, 30 % of 2 000,00 at 20 %, VAT on receipt: 411GIRA 720,00 / 419100 600,00 + 445800 120,00. */
    public function testADepositInvoiceCreditsTheAdvancesReceived(): void
    {
        $totals = SalesComputation::of([self::line('600.00')]);
        $entry = SalesEntries::propose(SalesDocumentType::Deposit, self::girard(), 'C0019', 'AC-2026-0001', new \DateTimeImmutable('2026-03-02'), null, [['account' => '706000', 'amount' => $totals->lineAmounts[0]]], $totals, true, 'VTE');

        self::assertSame([['411GIRA', '', 72_000, 0, null], ['419100', 'C0019', 0, 60_000, null], ['445800', '', 0, 12_000, null]], self::rows($entry));
    }

    /**
     * The final invoice of 2 × 1 000,00 at 20 % on 701000 deducts that deposit (VAT on the debit):
     * 411GIRA 1 680,00, 419100 600,00, 445710 120,00 taken back / 701000 2 000,00, 445710 400,00.
     */
    public function testAFinalInvoiceTakesItsDepositsBack(): void
    {
        $deposit = new SalesDeposit('AC-2026-0001', new \DateTimeImmutable('2026-03-02'), [new VatLine('20', Money::cents(60_000), Money::cents(12_000))], false);
        $totals = SalesComputation::of([self::line('1000.00', '2')], null, [$deposit]);
        $entry = SalesEntries::propose(SalesDocumentType::Invoice, self::girard(), 'C0019', 'FV-2026-0002', new \DateTimeImmutable('2026-03-15'), null, [['account' => '701000', 'amount' => $totals->lineAmounts[0]]], $totals, false, 'VTE');

        self::assertSame([['411GIRA', '', 168_000, 0, null], ['419100', 'C0019', 60_000, 0, null], ['445710', '', 12_000, 0, null], ['701000', '', 0, 200_000, null], ['445710', '', 0, 40_000, null]], self::rows($entry));
        self::assertSame(240_000, $entry->totalDebit());
    }

    /** AV-2026-0001, 100,00 of a free line at 20 %, VAT on receipt: the invoice's sides reversed. */
    public function testACreditNoteReversesTheSides(): void
    {
        $totals = SalesComputation::of([self::line('100.00')]);
        $entry = SalesEntries::propose(SalesDocumentType::CreditNote, self::girard(), 'C0019', 'AV-2026-0001', new \DateTimeImmutable('2026-03-15'), null, [['account' => SalesEntries::DEFAULT_REVENUE, 'amount' => $totals->lineAmounts[0]]], $totals, true, 'VTE');

        self::assertSame([['411GIRA', '', 0, 12_000, null], ['706000', '', 10_000, 0, null], ['445800', '', 2_000, 0, null]], self::rows($entry));
    }

    public function testAQuoteAsksForNoEntry(): void
    {
        $this->expectException(\LogicException::class);
        SalesEntries::propose(SalesDocumentType::Quote, self::girard(), 'C0019', 'DE-2026-0001', new \DateTimeImmutable('2026-03-15'), null, [], SalesComputation::of([]), false, 'VTE');
    }

    private static function girard(): PieceParty
    {
        return new PieceParty(ThirdPartyKind::Customer, '411GIRA', '', 'Girard, Jean et Anne');
    }

    private static function line(string $price, string $quantity = '1'): SalesLine
    {
        return new SalesLine(null, 'Ligne', '', BigDecimal::of($quantity), Money::euros($price), null, '20');
    }

    /** @return list<array{string, string, int, int, ?string}> */
    private static function rows(PieceProposal $entry): array
    {
        return array_map(static fn (GridLine $l): array => [$l->account, $l->thirdParty, $l->debit, $l->credit, $l->due], $entry->lines);
    }
}
