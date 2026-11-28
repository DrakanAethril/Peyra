<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Entity\Payroll\PayrollItem;
use App\Entity\Payroll\PayrollMonthClosure;
use App\Enum\Audit\AuditAction;
use App\Enum\Payroll\PayslipStatus;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\MonthlyPayslipRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Repository\Payroll\PayrollItemRepository;
use App\Repository\Payroll\PayrollMonthClosureRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipEstablishment;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Regulatory\RegulatoryReader;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The payslips of the month (steps 4.4a and 4.4b), à la manière de Sage: the month paid, the employees
 * it pays (under contract on one day of it at least), each one's payslip of the month, and the one door
 * that writes them - the elements of the month and what was changed by hand, the validation that
 * freezes a payslip, its cancellation, its sending (simulated), the closing and reopening of the month.
 *
 * The month paid is the one after the last month closed, or, before any closing, the dossier's date's.
 * The calculation is the engine's alone (PayslipCalculator), on the fiche at the payslip's day - the
 * month's last day -, the dossier's plan and establishment, the regulatory values of that day and the
 * employee's year to date: the sum of their validated payslips of the calendar year before this month
 * (PayslipSnapshot::yearToDateOf()). A validated payslip is never calculated again: its frozen lines
 * are read.
 */
final readonly class PayslipBook
{
    public function __construct(
        private EmployeeRepository $employees,
        private MonthlyPayslipRepository $payslips,
        private PayrollItemRepository $items,
        private PayrollEstablishmentRepository $establishments,
        private RegulatoryReader $regulatory,
        private PayrollMonthClosureRepository $closures,
        private AuditTrail $auditTrail,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** The first day of the month paid: the one after the last month closed, else the dossier's date's. */
    public function month(Dossier $dossier): \DateTimeImmutable
    {
        $last = $this->closures->findLast($dossier);

        return null === $last ? $dossier->getDossierDate()->modify('first day of this month') : $last->getMonth()->modify('first day of next month');
    }

    public function lastClosed(Dossier $dossier): ?PayrollMonthClosure
    {
        return $this->closures->findLast($dossier);
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

    /** The employee's year to date before that month: their validated payslips of the calendar year, summed. */
    public function yearToDate(Employee $employee, \DateTimeImmutable $month): PayslipYearToDate
    {
        $months = [];
        foreach ($this->payslips->findValidatedBetween($employee, $month->modify('first day of january this year'), $month) as $payslip) {
            $months[] = PayslipSnapshot::monthFigures($payslip->getFrozen() ?? []);
        }

        return PayslipSnapshot::yearToDateOf($months);
    }

    /**
     * The payslip as it stands: frozen when validated, else calculated from what it keeps; nothing before its first calculation.
     *
     * @return array{payslip: ?Payslip, refusal: ?string}
     */
    public function resultOf(MonthlyPayslip $payslip): array
    {
        $frozen = $payslip->getFrozen();
        if (null !== $frozen) {
            return ['payslip' => PayslipSnapshot::fromArray($frozen), 'refusal' => null];
        }
        if (PayslipStatus::ToDo === $payslip->getStatus()) {
            return ['payslip' => null, 'refusal' => null];
        }

        return $this->calculate($payslip->getEmployee(), $payslip->getMonth(), PayslipElements::fromArray($payslip->getElements()), $this->plan($payslip->getDossier()), PayslipAdjustments::fromArray($payslip->getAdjustments()));
    }

    /**
     * Calculates the payslip of that month from these elements and what was changed by hand, or says in
     * French why it cannot.
     *
     * @param list<PayrollItemSpec> $plan
     *
     * @return array{payslip: ?Payslip, refusal: ?string}
     */
    public function calculate(Employee $employee, \DateTimeImmutable $month, PayslipElements $elements, array $plan, ?PayslipAdjustments $adjustments = null): array
    {
        $establishment = $this->establishments->findOfDossier($employee->getDossier());
        if (null === $establishment) {
            return ['payslip' => null, 'refusal' => 'L’établissement n’est pas renseigné : Paie et social › Paramètres de paie › Établissement.'];
        }
        $day = self::payDay($month);
        try {
            $input = new PayslipInput(PayslipEmployee::at($employee, $day), $elements->toVariables(), $this->regulatory->at($day), $plan, PayslipEstablishment::of($establishment), $this->yearToDate($employee, $month), $adjustments ?? PayslipAdjustments::none());

            return ['payslip' => PayslipCalculator::calculate($input), 'refusal' => null];
        } catch (\InvalidArgumentException|\LogicException $error) {
            return ['payslip' => null, 'refusal' => $error->getMessage()];
        }
    }

    /** Keeps the elements and what was changed by hand on the payslip of the month (created at its first calculation) and marks it calculated; flushed by the caller. */
    public function record(Employee $employee, \DateTimeImmutable $month, PayslipElements $elements, PayslipAdjustments $adjustments): MonthlyPayslip
    {
        $payslip = $this->payslips->findOne($employee, $month);
        if (null === $payslip) {
            $payslip = new MonthlyPayslip($employee, $month);
            $this->entityManager->persist($payslip);
        }
        $payslip->calculated($elements->toArray(), $adjustments->toArray(), !$adjustments->isEmpty());

        return $payslip;
    }

    /**
     * Validates the payslip: calculated once more from what it keeps, then frozen on the dossier's date.
     * Null when done, else why not, in French. Flushed by the caller.
     */
    public function validate(MonthlyPayslip $payslip, User $actor): ?string
    {
        if (!$payslip->getStatus()->isToValidate()) {
            return 'Ce bulletin n’est pas calculé : calculez-le avant de le valider.';
        }
        $employee = $payslip->getEmployee();
        $month = $payslip->getMonth();
        $result = $this->resultOf($payslip);
        if (null === $result['payslip']) {
            return $result['refusal'] ?? 'Ce bulletin ne se calcule pas.';
        }
        $dossier = $payslip->getDossier();
        $payslip->validate(PayslipSnapshot::toArray($result['payslip'], $this->yearToDate($employee, $month)), $dossier->getDossierDate());
        $this->auditTrail->inDossier($dossier, AuditAction::PayslipValidated, $actor, \sprintf('Bulletin de %s validé (%s) : net à payer %s', $employee->getFullName(), FrenchFormat::period($month), FrenchFormat::amountInEuros($result['payslip']->totals->netPay)), ['employee' => $employee->getRegistrationNumber(), 'month' => $month->format('Y-m')]);

        return null;
    }

    /**
     * Cancels the validation of a payslip of the month paid (« Annuler le bulletin »): it goes back to
     * what it keeps, calculated again at the next reading; the audit trail keeps what was validated.
     * Null when done, else why not. Flushed by the caller.
     */
    public function cancel(MonthlyPayslip $payslip, User $actor): ?string
    {
        $dossier = $payslip->getDossier();
        if (!$payslip->isValidated() || $payslip->getMonth() != $this->month($dossier)) {
            return 'Seul un bulletin validé du mois de paie ouvert s’annule.';
        }
        $employee = $payslip->getEmployee();
        $this->auditTrail->inDossier($dossier, AuditAction::PayslipCancelled, $actor, \sprintf('Validation du bulletin de %s annulée (%s)', $employee->getFullName(), FrenchFormat::period($payslip->getMonth())), ['employee' => $employee->getRegistrationNumber(), 'month' => $payslip->getMonth()->format('Y-m'), 'frozen' => $payslip->getFrozen()]);
        $payslip->cancelValidation(!PayslipAdjustments::fromArray($payslip->getAdjustments())->isEmpty());

        return null;
    }

    /** Sends the validated payslip to the employee - simulated: the day is kept, nothing leaves Peyra. Flushed by the caller. */
    public function send(MonthlyPayslip $payslip, User $actor): void
    {
        if (null !== $payslip->getSentOn()) {
            return;
        }
        $dossier = $payslip->getDossier();
        $payslip->send($dossier->getDossierDate());
        $this->auditTrail->inDossier($dossier, AuditAction::PayslipSent, $actor, \sprintf('Bulletin de %s (%s) envoyé au salarié (simulé)', $payslip->getEmployee()->getFullName(), FrenchFormat::period($payslip->getMonth())));
    }

    /**
     * What stops the month paid from being closed, in French: a payslip not validated, named. Empty when it can be.
     *
     * @return list<string>
     */
    public function closingRefusals(Dossier $dossier): array
    {
        $month = $this->month($dossier);
        $payslips = $this->payslipsOf($dossier, $month);
        $refusals = [];
        foreach ($this->employeesOf($dossier, $month) as $employee) {
            if (!($payslips[(int) $employee->getId()] ?? null)?->isValidated()) {
                $refusals[] = \sprintf('Le bulletin de %s n’est pas validé.', $employee->getFullName());
            }
        }

        return $refusals;
    }

    /** Closes the month paid, its payslips all validated; the next month is the one paid. Null when done, else why not. Flushed by the caller. */
    public function close(Dossier $dossier, User $actor): ?string
    {
        $refusals = $this->closingRefusals($dossier);
        if ([] !== $refusals) {
            return $refusals[0];
        }
        $month = $this->month($dossier);
        $this->entityManager->persist(new PayrollMonthClosure($dossier, $month, $dossier->getDossierDate()));
        $this->auditTrail->inDossier($dossier, AuditAction::PayrollMonthClosed, $actor, \sprintf('Paie %s close', FrenchFormat::ofPeriod($month)), ['month' => $month->format('Y-m')]);

        return null;
    }

    /**
     * Reopens the last month closed (a teacher's gesture, the controller's to check): refused while a
     * payslip of the month paid is validated, since its year to date reads the month closed. Null when
     * done, else why not. Flushed by the caller.
     */
    public function reopen(Dossier $dossier, User $actor): ?string
    {
        $last = $this->closures->findLast($dossier);
        if (null === $last) {
            return 'Aucun mois de paie n’est clos.';
        }
        $open = $this->month($dossier);
        foreach ($this->payslipsOf($dossier, $open) as $payslip) {
            if ($payslip->isValidated()) {
                return \sprintf('Des bulletins %s sont validés : annulez-les avant de rouvrir %s.', FrenchFormat::ofPeriod($open), FrenchFormat::period($last->getMonth()));
            }
        }
        $this->entityManager->remove($last);
        $this->auditTrail->inDossier($dossier, AuditAction::PayrollMonthReopened, $actor, \sprintf('Paie %s rouverte', FrenchFormat::ofPeriod($last->getMonth())), ['month' => $last->getMonth()->format('Y-m')]);

        return null;
    }
}
