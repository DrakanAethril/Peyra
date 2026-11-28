<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollEstablishment;
use App\Repository\Payroll\MonthlyPayslipRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Service\Payroll\Employee\Nir;
use App\Service\Payroll\Payslip\PayslipBook;
use App\Service\Payroll\Payslip\PayslipSnapshot;
use App\Service\Payroll\Statement\PayrollStatementsReader;
use App\Service\Regulatory\MissingRegulatoryValue;

/**
 * Reads what the DSN's preparatory state of a month shows (step 4.6) - its validated payslips as they
 * were frozen, their employees on the pay day, the employees the month pays, the payments it leaves -
 * and hands them to the pure DsnPreparation. Read inside the dossier's context.
 */
final readonly class DsnStatements
{
    public function __construct(
        private PayrollStatementsReader $reader,
        private MonthlyPayslipRepository $payslips,
        private PayslipBook $book,
        private SocialSchedule $schedule,
        private PayrollEstablishmentRepository $establishments,
    ) {
    }

    /** @throws MissingRegulatoryValue */
    public function of(Dossier $dossier, \DateTimeImmutable $month): DsnPreparation
    {
        $day = PayslipBook::payDay($month);
        $individuals = [];
        foreach ($this->payslips->findValidatedOfMonth($dossier, $month) as $payslip) {
            $employee = $payslip->getEmployee();
            $terms = $employee->termsAt($day);
            $exitOn = $employee->getExitOn();
            $leaves = null !== $exitOn && $exitOn >= $month && $exitOn <= $day;
            $nir = $employee->getNir();
            $individuals[] = new DsnIndividual(
                $employee->getRegistrationNumber(), $employee->getLastName(), $employee->getFirstName(), $employee->getSex(),
                $employee->getBirthDate(), $employee->getBirthPlace(), null === $nir ? null : Nir::format($nir),
                $employee->getContractType(), $employee->getHiredOn(), $employee->getContractEndsOn(),
                $terms->getJobTitle(), $terms->isExecutive(), $terms->getWorkingTime(), $terms->getMonthlyHours(),
                $leaves ? $exitOn : null, $leaves ? $employee->getExitReason() : null,
                PayslipSnapshot::fromArray($payslip->getFrozen() ?? []),
            );
        }
        $expected = [];
        foreach ($this->book->employeesOf($dossier, $month) as $employee) {
            $expected[$employee->getRegistrationNumber()] = $employee->getLastName().' '.$employee->getFirstName();
        }
        $statements = $this->reader->of($dossier, $month);

        return DsnPreparation::of($statements, $individuals, $expected, $this->schedule->ofMonth($dossier, $statements));
    }

    public function establishment(Dossier $dossier): ?PayrollEstablishment
    {
        return $this->establishments->findOfDossier($dossier);
    }

    /** @throws MissingRegulatoryValue */
    public function dueOn(Dossier $dossier, \DateTimeImmutable $month): \DateTimeImmutable
    {
        return $this->schedule->dueOn($dossier, $month);
    }
}
