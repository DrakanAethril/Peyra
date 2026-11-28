<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/** Whom an item of the plan de paie applies to, by the employee's status: everybody, the non-executives, the executives (« cadres »). */
enum PayrollStatusCondition: string
{
    case All = 'all';
    case NonExecutive = 'non_executive';
    case Executive = 'executive';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Tous les salariés',
            self::NonExecutive => 'Non-cadres',
            self::Executive => 'Cadres',
        };
    }

    public function holds(bool $executive): bool
    {
        return match ($this) {
            self::All => true,
            self::NonExecutive => !$executive,
            self::Executive => $executive,
        };
    }
}
