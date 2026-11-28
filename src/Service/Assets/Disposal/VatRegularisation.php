<?php

declare(strict_types=1);

namespace App\Service\Assets\Disposal;

use App\Service\Shared\Money;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * The VAT given back when a fixed asset whose VAT was deducted leaves without VAT on its price (step
 * 3.4b; CGI, annexe II, art. 207): the VAT deducted × the years left of the regularisation period /
 * its years - five for a movable asset, twenty for a building, read in the regulatory values. The year
 * of acquisition is the period's first, the year of the way out counts as gone: bought in 2024, sold in
 * 2026 without VAT, 2 fifths are given back (2027 and 2028). Rounded to the cent, half up. Pure.
 */
final class VatRegularisation
{
    public static function reversal(Money $deducted, \DateTimeImmutable $acquiredOn, \DateTimeImmutable $disposedOn, int $periodYears): Money
    {
        if (!$deducted->isPositive() || $periodYears <= 0) {
            return Money::zero();
        }
        $left = ((int) $acquiredOn->format('Y') + $periodYears - 1) - (int) $disposedOn->format('Y');
        if ($left <= 0) {
            return Money::zero();
        }

        return Money::cents(BigRational::of($deducted->cents)->multipliedBy(min($left, $periodYears))->dividedBy($periodYears)->toScale(0, RoundingMode::HalfUp)->toInt());
    }

    /** A building (constructions, 213 and 214) is regularised over twenty years, any other asset over five. */
    public static function isBuilding(string $account): bool
    {
        return str_starts_with($account, '213') || str_starts_with($account, '214');
    }
}
