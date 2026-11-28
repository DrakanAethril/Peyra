<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Service\Document\VatLine;
use App\Service\Sales\SalesComputation;
use App\Service\Sales\SalesDeposit;
use App\Service\Sales\SalesLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * A commercial document's figures (step 2.3): the expected amounts are worked out by hand below,
 * from invoices of the demonstration's January (operations-2026.md § 2.2).
 */
final class SalesComputationTest extends TestCase
{
    /**
     * FV-2026-0003, Bâtir Loire Habitat: 8 × 1 380,00 = 11 040,00, discount 4 % = 441,60, net
     * 10 598,40; port 85,00; base 20 % = 10 683,40; VAT 2 136,68; total 12 820,08.
     */
    public function testADiscountAndThePortOnOneRate(): void
    {
        $totals = SalesComputation::of([
            self::line('8', '1380.00', '4', '20'),
            self::line('1', '85.00', null, '20'),
        ]);

        self::assertSame([1_059_840, 8_500], array_map(static fn (Money $m): int => $m->cents, $totals->lineAmounts));
        self::assertSame(44_160, $totals->discounts->cents);
        self::assertSame(1_068_340, $totals->excludingTax->cents);
        self::assertCount(1, $totals->vatLines);
        self::assertSame(['20', 1_068_340, 213_668], [$totals->vatLines[0]->rate, $totals->vatLines[0]->base->cents, $totals->vatLines[0]->vat->cents]);
        self::assertSame(1_282_008, $totals->includingTax->cents);
    }

    /**
     * Two rates, the highest first: 100,00 at 20 % (VAT 20,00) and 3 × 11,11 = 33,33 at 5,5 %
     * (33,33 × 0,055 = 1,83315, rounded 1,83); total 133,33 + 21,83 = 155,16.
     */
    public function testTheVatIsComputedOncePerRate(): void
    {
        $totals = SalesComputation::of([
            self::line('3', '11.11', null, '5.5'),
            self::line('1', '100.00', null, '20'),
        ]);

        self::assertSame(['20', '5.5'], array_map(static fn ($l): string => $l->rate, $totals->vatLines));
        self::assertSame([2_000, 183], array_map(static fn ($l): int => $l->vat->cents, $totals->vatLines));
        self::assertSame(13_333, $totals->excludingTax->cents);
        self::assertSame(15_516, $totals->includingTax->cents);
    }

    /**
     * Rounding half up: a discount of 5 % on 10,10 is 0,505 → 0,51, the line 9,59; the base at
     * 10 % is 9,59 + 9,59 + 0,05 = 19,23 and its VAT 1,923 → 1,92 - where adding each line's VAT
     * would give 0,96 + 0,96 + 0,01 = 1,93. Total 19,23 + 1,92 = 21,15.
     */
    public function testRoundingIsHalfUpOnTheBaseOfARate(): void
    {
        $totals = SalesComputation::of([
            self::line('1', '10.10', '5', '10'),
            self::line('1', '10.10', '5', '10'),
            self::line('1', '0.05', null, '10'),
        ]);

        self::assertSame([959, 959, 5], array_map(static fn (Money $m): int => $m->cents, $totals->lineAmounts));
        self::assertSame(102, $totals->discounts->cents);
        self::assertSame(1_923, $totals->excludingTax->cents);
        self::assertSame(192, $totals->vat->cents);
        self::assertSame(2_115, $totals->includingTax->cents);
    }

    /** A quantity with decimals: 3,5 ml × 1 150,00 = 4 025,00 (FV-2026-0010). */
    public function testADecimalQuantity(): void
    {
        self::assertSame(402_500, SalesComputation::of([self::line('3.5', '1150.00', null, '20')])->excludingTax->cents);
    }

    public function testNoLineIsZero(): void
    {
        $totals = SalesComputation::of([]);
        self::assertTrue($totals->includingTax->isZero());
        self::assertSame([], $totals->vatLines);
    }

    /**
     * A cash discount of 2 % (step 2.3b), taken off each rate's base before its VAT:
     * 20 %: 10 × 150,00 = 1 500,00, escompte 30,00, net 1 470,00, VAT 294,00;
     * 5,5 %: 2 × 49,90 = 99,80, escompte 1,996 → 2,00, net 97,80, VAT 5,379 → 5,38.
     * Net commercial 1 599,80, escompte 32,00, net financier 1 567,80, VAT 299,38, TTC 1 867,18.
     */
    public function testACashDiscountIsTakenOffEachBaseBeforeItsVat(): void
    {
        $totals = SalesComputation::of([
            self::line('10', '150.00', null, '20'),
            self::line('2', '49.90', null, '5.5'),
        ], BigDecimal::of('2'));

        self::assertSame(159_980, $totals->excludingTax->cents);
        self::assertSame(3_200, $totals->cashDiscount->cents);
        self::assertSame(156_780, $totals->netFinancial()->cents);
        self::assertSame([[147_000, 29_400], [9_780, 538]], array_map(static fn ($l): array => [$l->base->cents, $l->vat->cents], $totals->vatLines));
        self::assertSame(29_938, $totals->vat->cents);
        self::assertSame(186_718, $totals->includingTax->cents);
        self::assertSame(186_718, $totals->amountDue->cents, 'No deposit: all of it is due.');
    }

    /** Half up: 5 % of 10,10 is 0,505 → 0,51; net 9,59, VAT 1,918 → 1,92; TTC 11,51. */
    public function testTheCashDiscountIsRoundedHalfUp(): void
    {
        $totals = SalesComputation::of([self::line('1', '10.10', null, '20')], BigDecimal::of('5'));

        self::assertSame([51, 192, 1_151], [$totals->cashDiscount->cents, $totals->vat->cents, $totals->includingTax->cents]);
    }

    /**
     * A final invoice deducts its deposits from its total including tax: 2 × 1 000,00 at 20 % =
     * 2 400,00 TTC, less the deposit of 600,00 + 120,00 = 720,00: 1 680,00 due.
     */
    public function testTheDepositsAreDeductedFromTheTotal(): void
    {
        $deposit = new SalesDeposit('AC-2026-0001', new \DateTimeImmutable('2026-03-02'), [new VatLine('20', Money::cents(60_000), Money::cents(12_000))], false);
        $totals = SalesComputation::of([self::line('2', '1000.00', null, '20')], null, [$deposit]);

        self::assertSame(240_000, $totals->includingTax->cents);
        self::assertSame(72_000, $totals->depositsDeducted->cents);
        self::assertSame(168_000, $totals->amountDue->cents);
    }

    private static function line(string $quantity, string $price, ?string $discount, string $rate): SalesLine
    {
        return new SalesLine(null, 'Ligne', 'pièce', BigDecimal::of($quantity), Money::euros($price), null === $discount ? null : BigDecimal::of($discount), $rate);
    }
}
