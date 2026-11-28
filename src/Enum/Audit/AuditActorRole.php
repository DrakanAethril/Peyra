<?php

declare(strict_types=1);

namespace App\Enum\Audit;

/**
 * What the author of a line of a dossier's trail was when they acted: a teacher's gesture in a
 * student's dossier is signed with their name and says so (conception § 4). A command signs with
 * its own name and no role.
 */
enum AuditActorRole: string
{
    case Teacher = 'teacher';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Teacher => 'Enseignant',
            self::Student => 'Étudiant',
        };
    }
}
