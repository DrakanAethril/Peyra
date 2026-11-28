<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * What a dossier is (conception § 4): a teacher's template, a student's own copy, the copy a group
 * shares, or a restore point - a frozen copy that is never opened as a dossier -, or the copy of a
 * template a teacher plays a mission in to record its answer key (step 2.9b): their own, never a
 * student's, listed nowhere but on the mission.
 */
enum DossierKind: string implements HasBadge
{
    case Template = 'template';
    case Student = 'student';
    case Group = 'group';
    case Snapshot = 'snapshot';
    case AnswerKey = 'answer_key';

    public function label(): string
    {
        return match ($this) {
            self::Template => 'Dossier modèle',
            self::Student => 'Dossier d’étudiant',
            self::Group => 'Dossier de groupe',
            self::Snapshot => 'Point de restauration',
            self::AnswerKey => 'Corrigé de mission',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Template, self::AnswerKey => BadgeTone::Warning,
            self::Group => BadgeTone::Info,
            self::Student, self::Snapshot => BadgeTone::Neutral,
        };
    }
}
