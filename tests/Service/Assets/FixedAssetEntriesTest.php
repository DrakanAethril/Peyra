<?php

declare(strict_types=1);

namespace App\Tests\Service\Assets;

use App\Service\Assets\Disposal\DisposalCalculation;
use App\Service\Assets\Dotation\DotationLine;
use App\Service\Assets\FixedAssetEntries;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/** The fixed assets' entries (step 3.4b), each balanced, on amounts worked out by hand. */
final class FixedAssetEntriesTest extends TestCase
{
    public function testAnAcquisitionAndAProduction(): void
    {
        // The dust extractor I14: 6 000,00 + VAT 1 200,00 = 7 200,00 owed to 404ASPI.
        $entry = FixedAssetEntries::acquisition('ACH', new \DateTimeImmutable('2026-11-18'), 'ATR-26-1187', 'Dépoussiéreur', '215400', Money::euros('6000.00'), Money::euros('1200.00'), '404ASPI', '');
        self::assertSame([['215400', 600_000, 0], ['445620', 120_000, 0], ['404ASPI', 0, 720_000]], self::lines($entry));

        $entry = FixedAssetEntries::production('OD', new \DateTimeImmutable('2026-05-01'), 'IMM-P01', 'Établi', '215400', Money::euros('3250.40'), '722000');
        self::assertSame([['215400', 325_040, 0], ['722000', 0, 325_040]], self::lines($entry));
    }

    public function testTheDotationsAddUpAccountByAccount(): void
    {
        // 377,77 on an intangible asset, 4 200,00 + 1 749,78 on tangible ones, the planer's reprise of
        // 592,84 and an impairment of 1 000,00.
        $day = new \DateTimeImmutable('2026-12-31');
        $from = new \DateTimeImmutable('2026-01-01');
        $entry = FixedAssetEntries::dotations('OD', $day, 'DOT-2026-12-31', 'Dotations', [
            new DotationLine('I02', 'Logiciel', $from, $day, Money::euros('377.77'), '681110', '280500'),
            new DotationLine('I03', 'Agencements', $from, $day, Money::euros('4200.00'), '681120', '281810'),
            new DotationLine('I05', 'Raboteuse', $from, $day, Money::euros('1749.78'), '681120', '281540', Money::euros('-592.84')),
            new DotationLine('I01', 'Fonds', $from, $day, Money::zero(), '681110', null, null, Money::euros('1000.00'), '290700', '681610', '781610'),
        ]);
        self::assertSame([
            ['145000', 59_284, 0], ['681110', 37_777, 0], ['681120', 594_978, 0], ['681610', 100_000, 0],
            ['280500', 0, 37_777], ['281540', 0, 174_978], ['281810', 0, 420_000], ['290700', 0, 100_000], ['787250', 0, 59_284],
        ], self::lines($entry));
        self::assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    public function testAScrappingWithAnImpairmentAndAVatRegularisation(): void
    {
        // Cost 5 000,00, 3 000,00 in the books and 250,00 more: 657 gets 1 750,00; impairment 400,00
        // reversed; no price; VAT given back 240,00.
        $calculation = new DisposalCalculation(Money::euros('5000.00'), Money::euros('3000.00'), Money::euros('250.00'), Money::euros('400.00'), Money::zero(), Money::zero(), Money::zero(), Money::euros('240.00'));
        $entry = FixedAssetEntries::disposal('OD', new \DateTimeImmutable('2026-05-15'), 'CES-M01', 'Mise au rebut', $calculation, [
            'asset' => '215400', 'depreciation' => '281540', 'dotation' => '681120', 'impairment' => '291500',
            'impairmentReprise' => '781620', 'bookValueOut' => '657000', 'salePrice' => '757000',
        ], '462000', '');
        self::assertSame([
            ['681120', 25_000, 0], ['281540', 0, 25_000], ['281540', 325_000, 0], ['657000', 175_000, 0], ['215400', 0, 500_000],
            ['291500', 40_000, 0], ['781620', 0, 40_000], ['658000', 24_000, 0], ['445710', 0, 24_000],
        ], self::lines($entry));
        self::assertSame($entry->totalDebit(), $entry->totalCredit());
    }

    /** @return list<array{string, int, int}> */
    private static function lines(PieceProposal $entry): array
    {
        return array_map(static fn ($line): array => [$line->account, $line->debit, $line->credit], $entry->lines);
    }
}
