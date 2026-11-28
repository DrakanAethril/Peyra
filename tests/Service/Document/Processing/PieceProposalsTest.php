<?php

declare(strict_types=1);

namespace App\Tests\Service\Document\Processing;

use App\Enum\Accounting\JournalType;
use App\Enum\Document\DocumentType;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\Processing\PieceProposals;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The entry proposed from a pièce's read data (step 2.2a), on amounts worked out by hand: the
 * Scierie's invoice of maquette 1 (2 707,00 HT, TVA 20 % 541,40, 3 248,40 TTC), an invoice at two
 * rates, a supplier's credit note, a sales invoice, and what calls for no proposal.
 */
final class PieceProposalsTest extends TestCase
{
    public function testThePurchaseInvoiceOfMaquetteOne(): void
    {
        $proposal = $this->propose(DocumentType::PurchaseInvoice, $this->scierie(), [new VatLine('20', Money::cents(270_700), Money::cents(54_140))], 270_700, 324_840, '601000');

        self::assertNotNull($proposal);
        self::assertSame(['ACH', '2026-11-12', 'FA-2611-0457', 'Scierie du Haut-Forez FA-2611-0457'], [$proposal->journal, $proposal->date, $proposal->document, $proposal->label]);
        self::assertSame([
            ['601000', '', 270_700, 0, null],
            ['445660', '', 54_140, 0, null],
            ['401SCIE', '', 0, 324_840, '2026-12-12'],
        ], $this->lines($proposal));
        self::assertSame(324_840, $proposal->totalDebit());
        self::assertSame(324_840, $proposal->totalCredit());
    }

    public function testTwoRatesAndNoUsualAccountLeaveTheExpenseToTheStudent(): void
    {
        // 1 000,00 at 5,5 % (55,00) and 200,00 at 20 % (40,00): 1 295,00 TTC.
        $proposal = $this->propose(DocumentType::PurchaseInvoice, $this->scierie(), [
            new VatLine('5.5', Money::cents(100_000), Money::cents(5_500)),
            new VatLine('20', Money::cents(20_000), Money::cents(4_000)),
        ], 120_000, 129_500, null);

        self::assertNotNull($proposal);
        self::assertSame([
            ['', '', 120_000, 0, null],
            ['445660', '', 5_500, 0, null],
            ['445660', '', 4_000, 0, null],
            ['401SCIE', '', 0, 129_500, '2026-12-12'],
        ], $this->lines($proposal));
    }

    public function testASuppliersCreditNoteReversesTheSides(): void
    {
        // An avoir of 100,00 HT + 20,00 = 120,00.
        $proposal = $this->propose(DocumentType::CreditNote, $this->scierie(), [new VatLine('20', Money::cents(10_000), Money::cents(2_000))], 10_000, 12_000, '601000');

        self::assertNotNull($proposal);
        self::assertSame('ACH', $proposal->journal);
        self::assertSame([
            ['601000', '', 0, 10_000, null],
            ['445660', '', 0, 2_000, null],
            ['401SCIE', '', 12_000, 0, '2026-12-12'],
        ], $this->lines($proposal));
    }

    public function testASalesInvoiceDebitsTheCustomer(): void
    {
        // FV-2026-0001: 7 950,00 HT + 1 590,00 = 9 540,00; a customer kept by code on 411000.
        $girard = new PieceParty(ThirdPartyKind::Customer, '411000', 'C0019', 'Girard, Jean et Anne');
        $proposal = $this->propose(DocumentType::SalesInvoice, $girard, [new VatLine('20', Money::cents(795_000), Money::cents(159_000))], 795_000, 954_000, '701000', 'VTE');

        self::assertNotNull($proposal);
        self::assertSame([
            ['701000', '', 0, 795_000, null],
            ['445710', '', 0, 159_000, null],
            ['411000', 'C0019', 954_000, 0, '2026-12-12'],
        ], $this->lines($proposal));
    }

    public function testWithoutABreakdownTheTotalExcludingTaxIsTheExpense(): void
    {
        $proposal = $this->propose(DocumentType::PurchaseInvoice, $this->scierie(), [], 50_000, 50_000, '606300');

        self::assertNotNull($proposal);
        self::assertSame([['606300', '', 50_000, 0, null], ['401SCIE', '', 0, 50_000, '2026-12-12']], $this->lines($proposal));
    }

    public function testNothingIsProposedWithoutWhatAnEntryNeeds(): void
    {
        self::assertNull($this->propose(DocumentType::Receipt, $this->scierie(), [], 1_000, 1_200, null), 'Un ticket ne fait pas l’objet d’une proposition.');
        self::assertNull($this->propose(DocumentType::PurchaseInvoice, null, [], 1_000, 1_200, null), 'Sans tiers.');
        self::assertNull($this->propose(DocumentType::PurchaseInvoice, $this->scierie(), [], 1_000, null, null), 'Sans total TTC.');
        self::assertNull($this->propose(DocumentType::CreditNote, new PieceParty(ThirdPartyKind::Employee, '421000', 'S001', 'Lefèvre'), [], 1_000, 1_000, null), 'L’avoir d’un salarié.');
        self::assertSame(JournalType::Sales, PieceProposals::side(DocumentType::CreditNote, ThirdPartyKind::Customer));
    }

    public function testItSurvivesBeingKeptOnThePiece(): void
    {
        $proposal = $this->propose(DocumentType::PurchaseInvoice, $this->scierie(), [new VatLine('20', Money::cents(270_700), Money::cents(54_140))], 270_700, 324_840, '601000');
        self::assertNotNull($proposal);

        self::assertEquals($proposal, PieceProposal::fromArray(json_decode((string) json_encode($proposal->toArray()), true)));
        self::assertSame(['Scierie du Haut-Forez FA-2611-0457'], array_values(array_unique(array_map(static fn (GridLine $line): string => $line->label, $proposal->group()->lines))), 'Chaque ligne porte le libellé de l’écriture.');
    }

    private function scierie(): PieceParty
    {
        return new PieceParty(ThirdPartyKind::Supplier, '401SCIE', '', 'Scierie du Haut-Forez');
    }

    /** @param list<VatLine> $vat */
    private function propose(DocumentType $type, ?PieceParty $party, array $vat, int $excluding, ?int $including, ?string $usual, string $journal = 'ACH'): ?PieceProposal
    {
        return PieceProposals::propose(
            $type,
            $party,
            'FA-2611-0457',
            new \DateTimeImmutable('2026-11-12'),
            new \DateTimeImmutable('2026-12-12'),
            $vat,
            Money::cents($excluding),
            null === $including ? null : Money::cents($including),
            $journal,
            $usual,
            'basis',
        );
    }

    /** @return list<array{string, string, int, int, ?string}> */
    private function lines(PieceProposal $proposal): array
    {
        return array_map(static fn (GridLine $line): array => [$line->account, $line->thirdParty, $line->debit, $line->credit, $line->due], $proposal->lines);
    }
}
