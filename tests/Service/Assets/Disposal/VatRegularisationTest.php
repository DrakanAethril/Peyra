<?php

declare(strict_types=1);

namespace App\Tests\Service\Assets\Disposal;

use App\Service\Assets\Disposal\VatRegularisation;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/** The VAT given back on a way out without VAT (step 3.4b), worked out by hand. */
final class VatRegularisationTest extends TestCase
{
    public function testAMovableAssetGivesBackTheFifthsLeft(): void
    {
        // Bought in 2024 (VAT 1 200,00 deducted), sold without VAT in 2026: 2024, 2025, 2026 gone, 2027 and
        // 2028 left: 1 200 × 2 / 5 = 480,00.
        self::assertSame(48_000, VatRegularisation::reversal(Money::euros('1200.00'), new \DateTimeImmutable('2024-03-10'), new \DateTimeImmutable('2026-05-01'), 5)->cents);
        // 1 000,00 sold the year after: 3 fifths, 600,00; 333,33 × 1 / 5 = 66,666 → 66,67.
        self::assertSame(60_000, VatRegularisation::reversal(Money::euros('1000.00'), new \DateTimeImmutable('2025-12-31'), new \DateTimeImmutable('2026-01-02'), 5)->cents);
        self::assertSame(6_667, VatRegularisation::reversal(Money::euros('333.33'), new \DateTimeImmutable('2022-06-01'), new \DateTimeImmutable('2025-06-01'), 5)->cents);
    }

    public function testNothingOnceThePeriodIsOver(): void
    {
        self::assertTrue(VatRegularisation::reversal(Money::euros('1200.00'), new \DateTimeImmutable('2022-02-01'), new \DateTimeImmutable('2026-06-30'), 5)->isZero(), '2022 à 2026 : la période est close.');
        self::assertTrue(VatRegularisation::reversal(Money::zero(), new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2026-06-30'), 5)->isZero(), 'Rien de déduit, rien à reverser.');
    }

    public function testABuildingCountsTwentieths(): void
    {
        // Built in 2020 (VAT 10 000,00), sold without VAT in 2026: 2020 + 20 − 1 − 2026 = 13 twentieths, 6 500,00.
        self::assertSame(650_000, VatRegularisation::reversal(Money::euros('10000.00'), new \DateTimeImmutable('2020-09-01'), new \DateTimeImmutable('2026-01-15'), 20)->cents);
        self::assertTrue(VatRegularisation::isBuilding('213100'));
        self::assertFalse(VatRegularisation::isBuilding('218200'));
    }
}
