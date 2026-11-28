<?php

declare(strict_types=1);

namespace App\Service\Assets\Depreciation;

use App\Service\Regulatory\RegulatorySnapshot;
use Brick\Math\BigDecimal;

/**
 * The declining method's fiscal coefficient (art. 39 A CGI), read from the regulatory value
 * `fiscal.depreciation.declining_balance_coefficients` in force on the day of acquisition: 3 or 4
 * years, 5 or 6, more than 6. Null below 3 years, which the method does not allow.
 */
final class DecliningCoefficients
{
    public const string KEY = 'fiscal.depreciation.declining_balance_coefficients';

    public static function for(RegulatorySnapshot $snapshot, int $years): ?BigDecimal
    {
        $bracket = match (true) {
            $years < 3 => null,
            $years <= 4 => 'three_or_four_years',
            $years <= 6 => 'five_or_six_years',
            default => 'over_six_years',
        };

        return null === $bracket ? null : $snapshot->decimal(self::KEY, $bracket);
    }
}
