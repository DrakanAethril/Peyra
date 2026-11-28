<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * A decimal raised to a decimal exponent, without a float (lot 4, step 4.3b): the RGDU raises its
 * bracket to P = 1,75. The exponent is read as a fraction n / d (1,75 = 7 / 4): the base raised to n
 * exactly, then its d-th root by Newton's method, at the scale asked for (truncated, the last digit
 * possibly one unit low). The coefficient it feeds is rounded to four decimals: twenty are plenty.
 */
final class DecimalPower
{
    private const int MAX_TERM = 1000;

    public static function of(BigDecimal $base, BigDecimal $exponent, int $scale): BigDecimal
    {
        if ($base->isNegative() || $exponent->isNegative()) {
            throw new \InvalidArgumentException('Only a base and an exponent that are not negative.');
        }
        $fraction = BigRational::ofFraction($exponent->getUnscaledValue(), BigInteger::ten()->power($exponent->getScale()));
        $numerator = $fraction->getNumerator()->toInt();
        $denominator = $fraction->getDenominator()->toInt();
        if ($numerator > self::MAX_TERM || $denominator > self::MAX_TERM) {
            throw new \InvalidArgumentException(\sprintf('Exponent %s is too fine a fraction.', $exponent));
        }
        if ($base->isZero()) {
            return 0 === $numerator ? BigDecimal::one()->toScale($scale) : BigDecimal::zero()->toScale($scale);
        }
        $working = $scale + 10;
        $raised = $base->toScale($working + 10, RoundingMode::Down)->power($numerator)->toScale($working + 10, RoundingMode::Down);

        return self::root($raised, $denominator, $working)->toScale($scale, RoundingMode::Down);
    }

    /** The k-th root of a positive number, by Newton's method from above: y ← ((k − 1) y + a ÷ y^(k−1)) ÷ k. */
    private static function root(BigDecimal $value, int $k, int $scale): BigDecimal
    {
        if (1 === $k) {
            return $value->toScale($scale, RoundingMode::Down);
        }
        $guess = $value->isGreaterThan(1) ? $value : BigDecimal::one();
        $epsilon = BigDecimal::ofUnscaledValue(1, $scale);
        for ($i = 0; $i < 2000; ++$i) {
            $next = $guess->multipliedBy($k - 1)
                ->plus($value->dividedBy($guess->power($k - 1), $scale, RoundingMode::Down))
                ->dividedBy($k, $scale, RoundingMode::Down);
            if ($guess->minus($next)->abs()->isLessThanOrEqualTo($epsilon)) {
                return $next;
            }
            $guess = $next;
        }

        throw new \LogicException('Newton’s method did not settle.');
    }
}
