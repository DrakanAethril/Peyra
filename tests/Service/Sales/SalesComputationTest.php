<?php

declare(strict_types=1);

namespace App\Tests\Service\Sales;

use App\Service\Sales\SalesComputation;
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

    private static function line(string $quantity, string $price, ?string $discount, string $rate): SalesLine
    {
        return new SalesLine(null, 'Ligne', 'pièce', BigDecimal::of($quantity), Money::euros($price), null === $discount ? null : BigDecimal::of($discount), $rate);
    }
}
