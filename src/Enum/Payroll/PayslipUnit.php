<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What the base or the rate of a payslip line counts (lot 4, step 4.3): hours, calendar days, a number of
 * something (meals), euros, a percent of the base, euros an hour or a unit, or a coefficient (the
 * general reduction's, applied to the gross pay as it is: 0,1775 × 2 472,00). The line's amount is
 * always the base times the rate as they are shown, rounded to the cent, so that whoever checks a
 * line with the figures printed on it finds its amount.
 */
enum PayslipUnit: string
{
    case Hours = 'hours';
    case Days = 'days';
    case Count = 'count';
    case Euros = 'euros';
    case Percent = 'percent';
    case EurosPerHour = 'euros_per_hour';
    case EurosPerUnit = 'euros_per_unit';
    case Coefficient = 'coefficient';

    /** « 151,67 h », « 10 », « 2 400,00 € », « 3,00 % », « 15,8238 € », « 0,1775 » - as the payslip prints it. */
    public function format(BigDecimal $value): string
    {
        return match ($this) {
            self::Hours => FrenchFormat::hours($value),
            self::Days => FrenchFormat::number($value)."\u{a0}j",
            self::Count => FrenchFormat::number($value),
            self::Euros => FrenchFormat::quantity($value)."\u{a0}€",
            self::Percent => FrenchFormat::quantity($value, max(2, $value->strippedOfTrailingZeros()->getScale()))."\u{a0}%",
            self::EurosPerHour, self::EurosPerUnit => FrenchFormat::quantity($value, max(2, $value->strippedOfTrailingZeros()->getScale()))."\u{a0}€",
            self::Coefficient => FrenchFormat::quantity($value, max(4, $value->strippedOfTrailingZeros()->getScale())),
        };
    }

    /** The amount of a line: base × rate, a percent divided by a hundred, rounded half up to the cent. */
    public function times(BigDecimal $base, BigDecimal $rate): BigDecimal
    {
        $product = $base->multipliedBy($rate);
        if (self::Percent === $this) {
            $product = $product->dividedBy(100, $product->getScale() + 2);
        }

        return $product->toScale(2, RoundingMode::HalfUp);
    }
}
