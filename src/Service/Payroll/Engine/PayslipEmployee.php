<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Entity\Payroll\Employee;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What the payslip engine knows of an employee (lot 4, step 4.3): the terms in force on the
 * payslip's day, read once from the fiche, the withholding rate the tax office sent (null: none,
 * the neutral rate applies), the days the contract starts and ends (step 4.3d: a month the
 * contract does not cover whole; the start also says which regime an apprentice's exemption follows,
 * step 4.3e) and why it ends (step 4.3e: the indemnities of the last payslip). A value, so that the engine stays a function of its
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
        public ?\DateTimeImmutable $hiredOn = null,
        public ?\DateTimeImmutable $leavesOn = null,
        public ?ExitReason $exitReason = null,
    ) {
        $this->monthlyHours = BigDecimal::of($monthlyHours);
        if (!$this->monthlyHours->isPositive() || $baseSalary->isNegative() || true === $withholdingRate?->isNegative()
            || (null !== $hiredOn && null !== $leavesOn && $leavesOn < $hiredOn)) {
            throw new \InvalidArgumentException('An employee of a payslip works some hours for a salary that is not negative.');
        }
    }

    /** The employee as the fiche says on that day (Employee::termsAt()). */
    public static function at(Employee $employee, \DateTimeImmutable $day): self
    {
        $terms = $employee->termsAt($day);

        $rate = $employee->getWithholdingRate();

        return new self($employee->getFullName(), $terms->isExecutive(), $employee->getContractType(), $terms->getMonthlyHours(), $terms->getBaseSalary(), null === $rate ? null : BigDecimal::of($rate), $employee->getHiredOn(), $employee->getExitOn(), $employee->getExitReason());
    }

    public function isApprentice(): bool
    {
        return ContractType::Apprenticeship === $this->contractType;
    }

    /** The hourly rate of the contract, four decimals as the fiche and the payslip show it: salary ÷ monthly hours. */
    public function hourlyRate(): BigDecimal
    {
        return $this->baseSalary->toDecimal()->dividedBy($this->monthlyHours, 4, RoundingMode::HalfUp);
    }
}
