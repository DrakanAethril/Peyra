<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Entity\Payroll\PayrollItem;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\MonthlyPayslipRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipEstablishment;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Regulatory\RegulatoryReader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The payslips of the month (step 4.4a), à la manière de Sage: the month paid (the dossier's date's),
 * the employees it pays (under contract on one day of it at least), each one's payslip of the month,
 * and the one door that writes the elements of the month and calculates. The calculation is the
 * engine's alone (PayslipCalculator), on the fiche at the payslip's day - the month's last day -, the
 * dossier's plan and establishment, and the regulatory values of that day. The year to date stays
 * empty until payslips are validated (step 4.4b).
 */
final readonly class PayslipBook
{
    public function __construct(
        private EmployeeRepository $employees,
        private MonthlyPayslipRepository $payslips,
        private PayrollItemRepository $items,
        private PayrollEstablishmentRepository $establishments,
        private RegulatoryReader $regulatory,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** The first day of the month paid: the dossier's date's. */
    public static function month(Dossier $dossier): \DateTimeImmutable
    {
        return $dossier->getDossierDate()->modify('first day of this month');
    }

    /** The day of the payslip: the month's last. */
    public static function payDay(\DateTimeImmutable $month): \DateTimeImmutable
    {
        return $month->modify('last day of this month');
    }

    /** Whether the contract covers at least one day of the month. */
    public static function paysIn(Employee $employee, \DateTimeImmutable $month): bool
    {
        $exit = $employee->getExitOn();

        return $employee->getHiredOn() <= self::payDay($month) && (null === $exit || $exit >= $month);
    }

    /** @return list<Employee> the employees the month pays, by name */
    public function employeesOf(Dossier $dossier, \DateTimeImmutable $month): array
    {
        $paid = array_values(array_filter($this->employees->findByDossier($dossier), static fn (Employee $e): bool => self::paysIn($e, $month)));
        usort($paid, static fn (Employee $a, Employee $b): int => [$a->getSortName(), $a->getRegistrationNumber()] <=> [$b->getSortName(), $b->getRegistrationNumber()]);

        return $paid;
    }

    /** @return array<int, MonthlyPayslip> by employee id */
    public function payslipsOf(Dossier $dossier, \DateTimeImmutable $month): array
    {
        return $this->payslips->findOfMonth($dossier, $month);
    }

    public function payslipOf(Employee $employee, \DateTimeImmutable $month): ?MonthlyPayslip
    {
        return $this->payslips->findOne($employee, $month);
    }

    /** @return list<PayrollItemSpec> */
    public function plan(Dossier $dossier): array
    {
        return array_map(static fn (PayrollItem $item): PayrollItemSpec => $item->spec(), $this->items->findPlan($dossier));
    }

    public function hasEstablishment(Dossier $dossier): bool
    {
        return null !== $this->establishments->findOfDossier($dossier);
    }

    /**
     * Calculates the payslip of that month from these elements, or says in French why it cannot.
     *
     * @param list<PayrollItemSpec> $plan
     *
     * @return array{payslip: ?Payslip, refusal: ?string}
     */
    public function calculate(Employee $employee, \DateTimeImmutable $month, PayslipElements $elements, array $plan): array
    {
        $establishment = $this->establishments->findOfDossier($employee->getDossier());
        if (null === $establishment) {
            return ['payslip' => null, 'refusal' => 'L’établissement n’est pas renseigné : Paie et social › Paramètres de paie › Établissement.'];
        }
        $day = self::payDay($month);
        try {
            $input = new PayslipInput(PayslipEmployee::at($employee, $day), $elements->toVariables(), $this->regulatory->at($day), $plan, PayslipEstablishment::of($establishment), PayslipYearToDate::none());

            return ['payslip' => PayslipCalculator::calculate($input), 'refusal' => null];
        } catch (\InvalidArgumentException|\LogicException $error) {
            return ['payslip' => null, 'refusal' => $error->getMessage()];
        }
    }

    /** Keeps the elements on the payslip of the month (created at its first calculation) and marks it calculated; flushed by the caller. */
    public function record(Employee $employee, \DateTimeImmutable $month, PayslipElements $elements): MonthlyPayslip
    {
        $payslip = $this->payslips->findOne($employee, $month);
        if (null === $payslip) {
            $payslip = new MonthlyPayslip($employee, $month);
            $this->entityManager->persist($payslip);
        }
        $payslip->calculated($elements->toArray());

        return $payslip;
    }
}
