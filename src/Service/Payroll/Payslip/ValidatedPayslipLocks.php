<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Entity\Payroll\EmploymentTerms;
use App\Service\Shared\FrenchFormat;

/**
 * What a validated payslip keeps from being deleted or moved on the fiche (step 4.4c), pure: given the
 * months of the employee's validated payslips, the employee is not deleted, the terms one of them was
 * calculated on are not deleted, and an exit that falls in or before one of those months is neither
 * recorded, changed nor cancelled - the frozen payslip read it. Each answer is the sentence to show,
 * or null when the gesture may be made.
 */
final class ValidatedPayslipLocks
{
    /** @param list<\DateTimeImmutable> $validatedMonths the first days of the months of the validated payslips */
    public static function employeeDeletion(array $validatedMonths): ?string
    {
        return [] === $validatedMonths ? null : 'Ce salarié a des bulletins validés : il ne se supprime pas. Enregistrez plutôt sa sortie.';
    }

    /** @param list<\DateTimeImmutable> $validatedMonths */
    public static function termsDeletion(EmploymentTerms $terms, array $validatedMonths): ?string
    {
        foreach ($validatedMonths as $month) {
            if ($terms->getEmployee()->termsAt(PayslipBook::payDay($month)) === $terms) {
                return \sprintf('Les conditions du %s ont servi au bulletin validé %s : elles ne se suppriment pas.', FrenchFormat::date($terms->getEffectiveFrom()), FrenchFormat::ofPeriod($month));
            }
        }

        return null;
    }

    /**
     * An exit recorded, changed (its reason too) or cancelled: refused when a validated payslip's month is
     * not before the month of the exit as it stood or as it would stand. The caller asks only for a change.
     *
     * @param list<\DateTimeImmutable> $validatedMonths
     */
    public static function exitChange(?\DateTimeImmutable $before, ?\DateTimeImmutable $after, array $validatedMonths): ?string
    {
        $days = array_filter([$before, $after]);
        if ([] === $days) {
            return null;
        }
        $from = min($days)->modify('first day of this month');
        foreach ($validatedMonths as $month) {
            if ($month >= $from) {
                return \sprintf('Le bulletin %s est validé : la sortie qu’il a lue ne change plus. Annulez d’abord le bulletin, si son mois est encore ouvert.', FrenchFormat::ofPeriod($month));
            }
        }

        return null;
    }
}
