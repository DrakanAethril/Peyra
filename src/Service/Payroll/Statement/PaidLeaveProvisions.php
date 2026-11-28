<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Entity\Dossier\Dossier;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\MonthlyPayslipRepository;
use App\Service\Payroll\Payslip\PayslipSnapshot;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\Money;

/**
 * The provision for paid leave of a dossier at a day (step 4.5; PaidLeaveProvision is the rule): the
 * employees under contract that day, the validated payslips of the reference period up to it, the
 * regulatory values of the day. What the inventory of lot 3 reads; Paie et social › Éditions shows it.
 */
final readonly class PaidLeaveProvisions
{
    public function __construct(
        private EmployeeRepository $employees,
        private MonthlyPayslipRepository $payslips,
        private RegulatoryReader $regulatory,
    ) {
    }

    /**
     * @return array{start: \DateTimeImmutable, day: \DateTimeImmutable, lines: list<array{number: string, name: string, gross: Money, months: int, provision: Money, rate: \Brick\Math\BigDecimal, charges: Money, explanation: string}>, provision: Money, charges: Money}
     *
     * @throws \App\Service\Regulatory\MissingRegulatoryValue when the values of the day are not installed
     */
    public function at(Dossier $dossier, \DateTimeImmutable $day): array
    {
        $values = $this->regulatory->at($day);
        $start = PaidLeaveProvision::periodStart($day, $values->integer('social.paid_leave.reference_period', 'start_month'), $values->integer('social.paid_leave.reference_period', 'start_day'));
        $employees = [];
        foreach ($this->employees->findByDossier($dossier) as $employee) {
            $exit = $employee->getExitOn();
            if ($employee->getHiredOn() > $day || (null !== $exit && $exit < $day)) {
                continue;
            }
            $gross = Money::zero();
            $employer = Money::zero();
            $months = 0;
            foreach ($this->payslips->findValidatedBetween($employee, $start->modify('first day of this month'), $day->modify('first day of next month')) as $payslip) {
                $frozen = PayslipSnapshot::fromArray($payslip->getFrozen() ?? []);
                $gross = $gross->plus($frozen->gross);
                $employer = $employer->plus($frozen->totals->employerContributions);
                ++$months;
            }
            $employees[] = ['number' => $employee->getRegistrationNumber(), 'name' => $employee->getSortName(), 'gross' => $gross, 'employer' => $employer, 'months' => $months];
        }
        usort($employees, static fn (array $a, array $b): int => [$a['name'], $a['number']] <=> [$b['name'], $b['number']]);

        return ['start' => $start, 'day' => $day, ...PaidLeaveProvision::at($employees, $values->decimal('social.paid_leave.pay', 'tenth_of_reference_pay'))];
    }
}
