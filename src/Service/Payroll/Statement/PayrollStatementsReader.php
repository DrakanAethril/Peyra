<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollItem;
use App\Repository\Payroll\MonthlyPayslipRepository;
use App\Repository\Payroll\PayrollBodyRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Payroll\Payslip\PayslipSnapshot;

/**
 * The payroll states of a dossier's month (step 4.5), read on its validated payslips as they were
 * frozen (PayslipSnapshot), never calculated again, and on the dossier's plan de paie for where each
 * line is paid and posted. Read inside the dossier's context.
 */
final readonly class PayrollStatementsReader
{
    public function __construct(
        private MonthlyPayslipRepository $payslips,
        private PayrollItemRepository $items,
        private PayrollBodyRepository $bodies,
    ) {
    }

    /** @return list<\DateTimeImmutable> the months that have a validated payslip, the latest first */
    public function months(Dossier $dossier): array
    {
        return $this->payslips->findValidatedMonthsOf($dossier);
    }

    public function of(Dossier $dossier, \DateTimeImmutable $month): PayrollMonthStatements
    {
        $payslips = [];
        foreach ($this->payslips->findValidatedOfMonth($dossier, $month) as $payslip) {
            $employee = $payslip->getEmployee();
            $payslips[] = new PayrollMonthPayslip($employee->getRegistrationNumber(), $employee->getSortName(), PayslipSnapshot::fromArray($payslip->getFrozen() ?? []));
        }

        return PayrollMonthStatements::of($month, $payslips, $this->chart($dossier));
    }

    public function chart(Dossier $dossier): PayrollChart
    {
        return PayrollChart::of(array_map(static fn (PayrollItem $item) => $item->spec(), $this->items->findPlan($dossier)), $this->bodies->findByDossier($dossier));
    }
}
