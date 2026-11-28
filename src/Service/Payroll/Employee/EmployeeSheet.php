<?php

declare(strict_types=1);

namespace App\Service\Payroll\Employee;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Enum\Audit\AuditAction;
use App\Repository\Payroll\EmployeeRepository;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\FrenchFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door to a dossier's employees (Paie et social › Salariés, step 4.2a): checks an employee
 * as typed (EmployeeRules), creates, changes and deletes them - each gesture in the audit trail.
 * A change of the terms names its day: the terms of that day are corrected, or a new row of the
 * history starts there. The first terms always start on the day of hire, and follow it when it
 * moves; no later terms may start on or before it. Runs inside the dossier's context; the caller
 * checks DossierRightVoter::PAYROLL_ENTER and flushes.
 */
final readonly class EmployeeSheet
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmployeeRepository $employees,
        private PayrollEstablishmentRepository $establishments,
        private AuditTrail $auditTrail,
    ) {
    }

    /** A draft for a new employee: the next matricule, a CDI from the dossier's date, full time, non-cadre. */
    public function blank(Dossier $dossier): EmployeeDraft
    {
        $highest = 0;
        foreach ($this->employees->findByDossier($dossier) as $employee) {
            if (1 === preg_match('/^S(\d+)$/', $employee->getRegistrationNumber(), $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }
        $draft = new EmployeeDraft();
        $draft->registrationNumber = 'S'.str_pad((string) ($highest + 1), 3, '0', \STR_PAD_LEFT);
        $draft->contractType = 'permanent';
        $draft->hiredOn = $dossier->getDossierDate()->format('Y-m-d');
        $draft->status = 'non_executive';
        $draft->workingTime = 'full_time';

        return $draft;
    }

    /** What may be saved, or null with the draft's errors filled. A new employee's terms start on the day of hire. */
    public function check(EmployeeDraft $draft, Dossier $dossier, ?Employee $employee = null): ?EmployeeFields
    {
        $first = null === $employee ? null : $employee->getTerms()[0];
        if (null === $employee || ($first?->getEffectiveFrom()->format('Y-m-d') === $draft->termsFrom)) {
            // The first terms: they start on the day of hire, wherever it moves.
            $draft->termsFrom = $draft->hiredOn;
        }
        $fields = EmployeeRules::check(
            $draft,
            $this->establishments->findOfDossier($dossier)?->getMonthlyHours(),
            function (string $number) use ($dossier, $employee): ?string {
                $holder = $this->employees->findOneByNumber($dossier, $number);

                return null === $holder || $holder === $employee ? null : $holder->getFullName();
            },
        );
        if (null === $fields || null === $employee) {
            return $fields;
        }
        foreach (\array_slice($employee->getTerms(), 1) as $later) {
            if ($later->getEffectiveFrom() <= $fields->hiredOn) {
                $draft->errors['hiredOn'] = \sprintf('Les conditions du contrat changent le %s : la date d’entrée précède ce jour.', FrenchFormat::date($later->getEffectiveFrom()));

                return null;
            }
        }

        return $fields;
    }

    public function create(Dossier $dossier, EmployeeFields $fields, User|string $by): Employee
    {
        $employee = new Employee($dossier, $fields->registrationNumber, $fields->lastName, $fields->firstName, $fields->sex, $fields->birthDate, $fields->contractType, $fields->hiredOn);
        self::write($employee, $fields);
        $this->entityManager->persist($employee);
        $this->entityManager->persist(new EmploymentTerms($employee, $fields->hiredOn, $fields->jobTitle, $fields->classification, $fields->executive, $fields->workingTime, $fields->monthlyHours, $fields->baseSalary));
        $this->auditTrail->inDossier(
            $dossier,
            AuditAction::EmployeeCreated,
            $by,
            \sprintf('Salarié %s %s créé (%s, entrée le %s)', $fields->registrationNumber, $employee->getFullName(), $fields->contractType->label(), FrenchFormat::date($fields->hiredOn)),
            ['dossier' => $dossier->getId(), 'number' => $fields->registrationNumber],
        );

        return $employee;
    }

    /** False when nothing changed (nothing is written). */
    public function update(Employee $employee, EmployeeFields $fields, User|string $by): bool
    {
        $before = self::snapshot($employee);
        $first = $employee->getTerms()[0];
        $movedHire = $fields->hiredOn != $employee->getHiredOn();
        self::write($employee, $fields);
        if ($movedHire) {
            $first->startOn($fields->hiredOn);
        }
        $changed = array_keys(array_diff_assoc(self::snapshot($employee), $before));

        $terms = $employee->termsStartingOn($fields->termsFrom);
        $termsNote = null;
        if (null === $terms) {
            $this->entityManager->persist(new EmploymentTerms($employee, $fields->termsFrom, $fields->jobTitle, $fields->classification, $fields->executive, $fields->workingTime, $fields->monthlyHours, $fields->baseSalary));
            $termsNote = \sprintf('nouvelles conditions à compter du %s', FrenchFormat::date($fields->termsFrom));
        } elseif (self::termsDiffer($terms, $fields)) {
            $terms->describe($fields->jobTitle, $fields->classification, $fields->executive, $fields->workingTime, $fields->monthlyHours, $fields->baseSalary);
            $termsNote = \sprintf('conditions du %s corrigées', FrenchFormat::date($fields->termsFrom));
        }
        if ([] === $changed && null === $termsNote) {
            return false;
        }
        $this->auditTrail->inDossier(
            $employee->getDossier(),
            AuditAction::EmployeeChanged,
            $by,
            \sprintf('Salarié %s modifié', $employee->getRegistrationNumber()).(null === $termsNote ? '' : ' : '.$termsNote),
            ['dossier' => $employee->getDossier()->getId(), 'number' => $employee->getRegistrationNumber(), 'fields' => $changed, 'before' => array_intersect_key($before, array_flip($changed)), 'terms' => $fields->termsFrom->format('Y-m-d')],
        );

        return true;
    }

    /** Why these terms may not be deleted, or null when they may: the first ones start the contract. */
    public static function termsDeletionRefusal(EmploymentTerms $terms): ?string
    {
        return $terms === $terms->getEmployee()->getTerms()[0] ? 'Les conditions de l’embauche ne se suppriment pas : corrigez-les.' : null;
    }

    public function deleteTerms(EmploymentTerms $terms, User|string $by): void
    {
        if (null !== self::termsDeletionRefusal($terms)) {
            throw new \LogicException('The terms of the hire stay.');
        }
        $employee = $terms->getEmployee();
        $employee->removeTerms($terms);
        $this->entityManager->remove($terms);
        $this->auditTrail->inDossier(
            $employee->getDossier(),
            AuditAction::EmployeeChanged,
            $by,
            \sprintf('Salarié %s : conditions du %s supprimées', $employee->getRegistrationNumber(), FrenchFormat::date($terms->getEffectiveFrom())),
            ['dossier' => $employee->getDossier()->getId(), 'number' => $employee->getRegistrationNumber(), 'terms' => $terms->getEffectiveFrom()->format('Y-m-d'), 'deleted' => true],
        );
    }

    public function delete(Employee $employee, User|string $by): void
    {
        $this->auditTrail->inDossier(
            $employee->getDossier(),
            AuditAction::EmployeeDeleted,
            $by,
            \sprintf('Salarié %s %s supprimé', $employee->getRegistrationNumber(), $employee->getFullName()),
            ['dossier' => $employee->getDossier()->getId(), 'number' => $employee->getRegistrationNumber()],
        );
        $this->entityManager->remove($employee);
    }

    private static function write(Employee $employee, EmployeeFields $fields): void
    {
        $employee->identify($fields->registrationNumber, $fields->lastName, $fields->firstName, $fields->sex, $fields->birthDate, $fields->birthPlace, $fields->nationality, $fields->nir);
        $employee->locate($fields->street, $fields->postalCode, $fields->city);
        $employee->withhold($fields->withholdingRate);
        $employee->contract($fields->contractType, $fields->hiredOn, $fields->contractEndsOn, $fields->fixedTermReason, $fields->diploma, $fields->trainingCentre);
    }

    private static function termsDiffer(EmploymentTerms $terms, EmployeeFields $fields): bool
    {
        return $terms->getJobTitle() !== $fields->jobTitle
            || $terms->getClassification() !== $fields->classification
            || $terms->isExecutive() !== $fields->executive
            || $terms->getWorkingTime() !== $fields->workingTime
            || $terms->getMonthlyHours() !== $fields->monthlyHours
            || !$terms->getBaseSalary()->equals($fields->baseSalary);
    }

    /** @return array<string, string> the civil status and the contract as text, for comparing two states */
    private static function snapshot(Employee $employee): array
    {
        return [
            'registrationNumber' => $employee->getRegistrationNumber(), 'lastName' => $employee->getLastName(), 'firstName' => $employee->getFirstName(),
            'sex' => $employee->getSex()->value, 'birthDate' => $employee->getBirthDate()->format('Y-m-d'), 'birthPlace' => (string) $employee->getBirthPlace(),
            'nationality' => $employee->getNationality(), 'nir' => (string) $employee->getNir(), 'street' => (string) $employee->getStreet(),
            'postalCode' => (string) $employee->getPostalCode(), 'city' => (string) $employee->getCity(), 'withholdingRate' => (string) $employee->getWithholdingRate(),
            'contractType' => $employee->getContractType()->value, 'hiredOn' => $employee->getHiredOn()->format('Y-m-d'),
            'contractEndsOn' => (string) $employee->getContractEndsOn()?->format('Y-m-d'), 'fixedTermReason' => (string) $employee->getFixedTermReason(),
            'diploma' => (string) $employee->getDiploma(), 'trainingCentre' => (string) $employee->getTrainingCentre(),
        ];
    }
}
