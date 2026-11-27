<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * What a dossier is (conception § 4): a teacher's template, a student's own copy, the copy a group
 * shares, or a restore point - a frozen copy that is never opened as a dossier.
 */
enum DossierKind: string implements HasBadge
{
    case Template = 'template';
    case Student = 'student';
    case Group = 'group';
    case Snapshot = 'snapshot';

    public function label(): string
    {
        return match ($this) {
            self::Template => 'Dossier modèle',
            self::Student => 'Dossier d’étudiant',
            self::Group => 'Dossier de groupe',
            self::Snapshot => 'Point de restauration',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Template => BadgeTone::Warning,
            self::Group => BadgeTone::Info,
            self::Student, self::Snapshot => BadgeTone::Neutral,
        };
    }
}
