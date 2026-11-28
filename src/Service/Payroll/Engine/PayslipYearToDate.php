<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;

/**
 * An employee's cumulative figures for the calendar year (lot 4, step 4.3c), what the progressive
 * regularisation reads: the gross pay, the monthly ceilings of the months paid, the SMIC of reference
 * of the general reduction and the reduction made, the overtime exempt from income tax, the net
 * taxable, and an apprentice's salary exempt from income tax (step 4.3e, within the annual SMIC). The engine takes them **before** the payslip (the sum of the year's earlier payslips, the
 * caller's) and gives them back **after** it; nothing here is stored.
 */
final readonly class PayslipYearToDate
{
    public Money $gross;
    public Money $ceiling;
    public Money $smic;
    public Money $generalReduction;
    public Money $exemptOvertime;
    public Money $netTaxable;
    public Money $apprenticeExempt;

    public function __construct(?Money $gross = null, ?Money $ceiling = null, ?Money $smic = null, ?Money $generalReduction = null, ?Money $exemptOvertime = null, ?Money $netTaxable = null, ?Money $apprenticeExempt = null)
    {
        $this->gross = $gross ?? Money::zero();
        $this->ceiling = $ceiling ?? Money::zero();
        $this->smic = $smic ?? Money::zero();
        $this->generalReduction = $generalReduction ?? Money::zero();
        $this->exemptOvertime = $exemptOvertime ?? Money::zero();
        $this->netTaxable = $netTaxable ?? Money::zero();
        $this->apprenticeExempt = $apprenticeExempt ?? Money::zero();
        foreach ([$this->gross, $this->ceiling, $this->smic, $this->generalReduction, $this->exemptOvertime, $this->apprenticeExempt] as $figure) {
            if ($figure->isNegative()) {
                throw new \InvalidArgumentException('The figures of a year so far are not negative.');
            }
        }
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->gross->isZero() && $this->ceiling->isZero();
    }
}
