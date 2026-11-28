<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/**
 * How a template is handed out (design-ux.md § 4.2, step 2): a dossier of their own for each
 * student, or one dossier shared by each group, its members each with a profile.
 */
enum DistributionMode: string
{
    case Individual = 'individual';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Un dossier par étudiant',
            self::Group => 'Un dossier partagé par groupe',
        };
    }
}
