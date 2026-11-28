<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The « éléments du mois » of one employee (lot 4, step 4.3), entered on the rubriques of the
 * plan de paie à la manière de Sage, by code: hours or a number on an item that counts them
 * (overtime, an absence, meals), an amount on an item that is one (a bonus, a benefit in kind),
 * a percent on an item whose rate is entered (the seniority bonus) - and the paid leave taken. A
 * sick pay item takes both: the hours its salary is kept for, and the daily allowances of the social
 * security for those days (step 4.3d). The whole calendar days of absence the employer paid nothing for
 * (`unpaidDays`, step 4.3d) take their days off the month's ceiling.
 * A rubrique with nothing entered does not appear on the payslip.
 */
final readonly class PayslipVariables
{
    /**
     * @param array<array-key, BigDecimal> $quantities hours or numbers, by code (a code of digits is an int key in PHP)
     * @param array<array-key, Money>      $amounts    by code
     * @param array<array-key, BigDecimal> $rates      percents (« 3 » is 3 %), by code
     */
    public function __construct(
        public array $quantities = [],
        public array $amounts = [],
        public array $rates = [],
        public ?PaidLeave $paidLeave = null,
        public int $unpaidDays = 0,
    ) {
        if ($unpaidDays < 0) {
            throw new \InvalidArgumentException('A number of days of absence is not negative.');
        }
        foreach ([$quantities, $rates] as $list) {
            foreach ($list as $value) {
                if ($value->isNegative()) {
                    throw new \InvalidArgumentException('An element of the month is not negative: an absence is an item that subtracts.');
                }
            }
        }
        foreach ($amounts as $amount) {
            if ($amount->isNegative()) {
                throw new \InvalidArgumentException('An element of the month is not negative: an absence is an item that subtracts.');
            }
        }
    }

    public static function none(): self
    {
        return new self();
    }

    public function quantity(string $code): ?BigDecimal
    {
        $value = $this->quantities[$code] ?? null;

        return null === $value || $value->isZero() ? null : $value;
    }

    public function amount(string $code): ?Money
    {
        $value = $this->amounts[$code] ?? null;

        return null === $value || $value->isZero() ? null : $value;
    }

    public function rate(string $code): ?BigDecimal
    {
        $value = $this->rates[$code] ?? null;

        return null === $value || $value->isZero() ? null : $value;
    }
}
