<?php

declare(strict_types=1);

namespace App\Service\Payroll\Exit;

use App\Entity\Identity\User;
use App\Entity\Payroll\Employee;
use App\Enum\Audit\AuditAction;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\FrenchFormat;

/**
 * The one door to an employee's exit (Paie et social › Salariés › Fin de contrat, step 4.2c):
 * checks it as typed (ExitRules, over the employee's contract), records or changes it, cancels it -
 * each gesture in the audit trail. Runs inside the dossier's context; the caller checks
 * DossierRightVoter::PAYROLL_ENTER and flushes.
 */
final readonly class EmployeeExits
{
    public function __construct(private AuditTrail $auditTrail)
    {
    }

    /** What may be recorded, or null with the draft's errors filled. */
    public function check(ExitDraft $draft, Employee $employee): ?ExitFields
    {
        $terms = $employee->getTerms();
        $latest = $terms[array_key_last($terms) ?? throw new \LogicException('An employee has terms.')];

        return ExitRules::check($draft, $employee->getContractType(), $employee->getHiredOn(), $employee->getContractEndsOn(), $latest->getEffectiveFrom());
    }

    /** False when nothing changed (nothing is written). */
    public function record(Employee $employee, ExitFields $fields, User|string $by): bool
    {
        if ($employee->getExitReason() === $fields->reason && $employee->getExitNotifiedOn() == $fields->notifiedOn && $employee->getExitOn() == $fields->exitOn) {
            return false;
        }
        $employee->leave($fields->reason, $fields->notifiedOn, $fields->exitOn);
        $this->auditTrail->inDossier(
            $employee->getDossier(),
            AuditAction::EmployeeExitRecorded,
            $by,
            \sprintf('Sortie de %s %s enregistrée : %s, dernier jour le %s', $employee->getRegistrationNumber(), $employee->getFullName(), mb_strtolower($fields->reason->label()), FrenchFormat::date($fields->exitOn)),
            ['dossier' => $employee->getDossier()->getId(), 'number' => $employee->getRegistrationNumber(), 'reason' => $fields->reason->value, 'notifiedOn' => $fields->notifiedOn?->format('Y-m-d'), 'exitOn' => $fields->exitOn->format('Y-m-d')],
        );

        return true;
    }

    public function cancel(Employee $employee, User|string $by): void
    {
        $exitOn = $employee->getExitOn() ?? throw new \LogicException('No exit to cancel.');
        $employee->leave(null, null, null);
        $this->auditTrail->inDossier(
            $employee->getDossier(),
            AuditAction::EmployeeExitCancelled,
            $by,
            \sprintf('Sortie de %s %s du %s annulée', $employee->getRegistrationNumber(), $employee->getFullName(), FrenchFormat::date($exitOn)),
            ['dossier' => $employee->getDossier()->getId(), 'number' => $employee->getRegistrationNumber(), 'exitOn' => $exitOn->format('Y-m-d')],
        );
    }
}
