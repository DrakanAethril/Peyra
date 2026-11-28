<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Entity\Payroll\PayrollEstablishment;
use Brick\Math\BigDecimal;

/**
 * What the payslip engine knows of the establishment (lot 4, step 4.3b): its headcount, which decides
 * the conditions of the plan de paie and the general reduction's parameters, its work accident and
 * mobility rates (in percent; no mobility rate when none is due), and its collective monthly hours,
 * the full time a part time's SMIC is prorated on. A value; tests write it by hand.
 */
final readonly class PayslipEstablishment
{
    public BigDecimal $headcount;
    public BigDecimal $workAccidentRate;
    public ?BigDecimal $mobilityRate;
    public BigDecimal $monthlyHours;

    public function __construct(BigDecimal|string $headcount, BigDecimal|string $workAccidentRate, BigDecimal|string|null $mobilityRate, BigDecimal|string $monthlyHours)
    {
        $this->headcount = BigDecimal::of($headcount);
        $this->workAccidentRate = BigDecimal::of($workAccidentRate);
        $this->mobilityRate = null === $mobilityRate ? null : BigDecimal::of($mobilityRate);
        $this->monthlyHours = BigDecimal::of($monthlyHours);
        if ($this->headcount->isNegative() || $this->workAccidentRate->isNegative() || true === $this->mobilityRate?->isNegative() || !$this->monthlyHours->isPositive()) {
            throw new \InvalidArgumentException('An establishment has a headcount and rates that are not negative, and some collective hours.');
        }
    }

    public static function of(PayrollEstablishment $establishment): self
    {
        return new self($establishment->getHeadcount(), $establishment->getWorkAccidentRate(), $establishment->getMobilityRate(), $establishment->getMonthlyHours());
    }
}
