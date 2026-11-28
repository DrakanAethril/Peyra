<?php

declare(strict_types=1);

namespace App\Service\Payroll\Employee;

use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmploymentTerms;
use App\Service\Shared\FrenchFormat;

/**
 * An employee's history as the fiche shows it (step 4.2a), pure: each terms with what it changed
 * from the ones before (« Salaire de base : 2 250,00 → 2 380,00 »), the hire first, and whether it
 * is past, in force or still to come at the dossier's date.
 */
final class EmploymentHistory
{
    /**
     * @return list<array{terms: EmploymentTerms, changes: list<string>, when: 'past'|'current'|'upcoming'}> from the hire on
     */
    public static function of(Employee $employee, \DateTimeImmutable $today): array
    {
        $current = $employee->termsAt($today);
        $rows = [];
        $previous = null;
        foreach ($employee->getTerms() as $terms) {
            $rows[] = [
                'terms' => $terms,
                'changes' => null === $previous ? ['Embauche'] : self::changes($previous, $terms),
                'when' => $terms->getEffectiveFrom() > $today ? 'upcoming' : ($terms === $current ? 'current' : 'past'),
            ];
            $previous = $terms;
        }

        return $rows;
    }

    /** @return list<string> what changed from the terms before; an empty list when nothing did */
    public static function changes(EmploymentTerms $before, EmploymentTerms $after): array
    {
        $changes = [];
        $text = static fn (string $label, string $from, string $to): string => \sprintf('%s : %s → %s', $label, '' === $from ? '—' : $from, '' === $to ? '—' : $to);
        if ($before->getJobTitle() !== $after->getJobTitle()) {
            $changes[] = $text('Emploi', $before->getJobTitle(), $after->getJobTitle());
        }
        if ($before->getClassification() !== $after->getClassification()) {
            $changes[] = $text('Classification', $before->getClassification(), $after->getClassification());
        }
        if ($before->isExecutive() !== $after->isExecutive()) {
            $changes[] = $text('Statut', $before->getStatusLabel(), $after->getStatusLabel());
        }
        if ($before->getWorkingTime() !== $after->getWorkingTime() || $before->getMonthlyHours() !== $after->getMonthlyHours()) {
            $changes[] = $text('Temps de travail', self::time($before), self::time($after));
        }
        if (!$before->getBaseSalary()->equals($after->getBaseSalary())) {
            $changes[] = $text('Salaire de base', FrenchFormat::amount($before->getBaseSalary()), FrenchFormat::amount($after->getBaseSalary()));
        }

        return $changes;
    }

    private static function time(EmploymentTerms $terms): string
    {
        return $terms->getWorkingTime()->label().', '.FrenchFormat::hours($terms->getMonthlyHours());
    }
}
