<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Entity\Payroll\Employee;
use App\Enum\Payroll\ContractType;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What the payslip engine knows of an employee (lot 4, step 4.3): the terms in force on the
 * payslip's day, read once from the fiche, and the withholding rate the tax office sent (null: none,
 * the neutral rate applies). A value, so that the engine stays a function of its
 * arguments; tests write it by hand.
 */
final readonly class PayslipEmployee
{
    public BigDecimal $monthlyHours;

    public function __construct(
        public string $name,
        public bool $executive,
        public ContractType $contractType,
        BigDecimal|string $monthlyHours,
        public Money $baseSalary,
        public ?BigDecimal $withholdingRate = null,
    ) {
        $this->monthlyHours = BigDecimal::of($monthlyHours);
        if (!$this->monthlyHours->isPositive() || $baseSalary->isNegative() || true === $withholdingRate?->isNegative()) {
            throw new \InvalidArgumentException('An employee of a payslip works some hours for a salary that is not negative.');
        }
    }

    /** The employee as the fiche says on that day (Employee::termsAt()). */
    public static function at(Employee $employee, \DateTimeImmutable $day): self
    {
        $terms = $employee->termsAt($day);

        $rate = $employee->getWithholdingRate();

        return new self($employee->getFullName(), $terms->isExecutive(), $employee->getContractType(), $terms->getMonthlyHours(), $terms->getBaseSalary(), null === $rate ? null : BigDecimal::of($rate));
    }

    /** The hourly rate of the contract, four decimals as the fiche and the payslip show it: salary ÷ monthly hours. */
    public function hourlyRate(): BigDecimal
    {
        return $this->baseSalary->toDecimal()->dividedBy($this->monthlyHours, 4, RoundingMode::HalfUp);
    }
}
