<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking;

use App\Entity\Banking\BankRule;
use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Banking\Reconciliation\BankLineEntry;
use App\Service\Banking\Rule\BankRuleMatch;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The entry a statement line asks for: the carburant of mock-up 2 (96,40 at 20 %: 80,33 and 16,07,
 * operations-2026.md § 3.3), a fee without VAT, a receipt with collected VAT; and the rule a wording
 * falls under.
 */
final class BankLineEntryTest extends TestCase
{
    public function testAPaymentSplitsItsVatOffAndCreditsTheBank(): void
    {
        self::assertSame([
            ['606110', 8_033, 0, ''],
            ['445660', 1_607, 0, ''],
            ['512100', 0, 9_640, ''],
        ], self::read(BankLineEntry::lines(Money::cents(-9_640), '512100', '606110', '', '20', 'CB STATION U')));
    }

    public function testAFeeWithoutVatIsTwoLines(): void
    {
        self::assertSame([
            ['627800', 2_400, 0, ''],
            ['512100', 0, 2_400, ''],
        ], self::read(BankLineEntry::lines(Money::cents(-2_400), '512100', '627800', '', null, 'FRAIS TENUE DE COMPTE')));
    }

    public function testAReceiptCreditsItsAccountAndTheCollectedVat(): void
    {
        // 120,00 at 5,5 %: 113,74 and 6,26.
        self::assertSame([
            ['706000', 0, 11_374, ''],
            ['445710', 0, 626, ''],
            ['512100', 12_000, 0, ''],
        ], self::read(BankLineEntry::lines(Money::cents(12_000), '512100', '706000', '', '5.5', 'VIR')));
        self::assertSame([['411000', 0, 432_000, 'C0008'], ['512100', 432_000, 0, '']], self::read(BankLineEntry::lines(Money::cents(432_000), '512100', '411000', 'C0008', null, 'VIR SEPA DUMAS')));
    }

    public function testTheFirstRuleWhoseTextTheWordingContainsAccentsAndCaseAside(): void
    {
        $dossier = (new \ReflectionClass(Dossier::class))->newInstanceWithoutConstructor();
        $fees = new BankRule($dossier, 'frais tenue', '627800', null, '');
        $fuel = new BankRule($dossier, 'Station U', '606110', '20', 'Carburant');
        $any = new BankRule($dossier, 'CB', '625100', null, '');

        self::assertSame($fees, BankRuleMatch::first([$fees, $fuel, $any], 'FRAIS TENUE DE COMPTE'));
        self::assertSame($fuel, BankRuleMatch::first([$fees, $fuel, $any], 'CB STATION U MONTBRISON'));
        self::assertSame($any, BankRuleMatch::first([$any, $fuel], 'CB STATION U MONTBRISON'));
        self::assertNull(BankRuleMatch::first([$fees, $fuel], 'PRLV URSSAF'));
    }

    /**
     * @param list<EntryLineDraft> $lines
     *
     * @return list<array{string, int, int, string}>
     */
    private static function read(array $lines): array
    {
        return array_map(static fn (EntryLineDraft $line): array => [$line->account, $line->debit->cents, $line->credit->cents, $line->thirdParty], $lines);
    }
}
