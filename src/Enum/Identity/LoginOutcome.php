<?php

declare(strict_types=1);

namespace App\Enum\Identity;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** One line of the sign-in journal (Administration › Journal des connexions). */
enum LoginOutcome: string implements HasBadge
{
    case Success = 'success';

    /** Unknown login or wrong password: the page says the same for both. */
    case BadCredentials = 'bad_credentials';

    case RefusedNotInProgramme = 'refused_not_in_programme';
    case RefusedTeacherNotAuthorized = 'refused_teacher_not_authorized';

    /** Too many attempts from that address or on that login. */
    case Throttled = 'throttled';

    /** The directory did not answer. */
    case DirectoryUnavailable = 'directory_unavailable';

    public static function ofRefusal(EntryRefusal $refusal): self
    {
        return match ($refusal) {
            EntryRefusal::NotInProgramme => self::RefusedNotInProgramme,
            EntryRefusal::TeacherNotAuthorized => self::RefusedTeacherNotAuthorized,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Réussie',
            self::BadCredentials => 'Identifiant ou mot de passe incorrect',
            self::RefusedNotInProgramme => 'Refusée : hors BTS CG',
            self::RefusedTeacherNotAuthorized => 'Refusée : enseignant non autorisé',
            self::Throttled => 'Bloquée : trop de tentatives',
            self::DirectoryUnavailable => 'Annuaire injoignable',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Success => BadgeTone::Success,
            self::BadCredentials, self::DirectoryUnavailable => BadgeTone::Neutral,
            self::RefusedNotInProgramme, self::RefusedTeacherNotAuthorized => BadgeTone::Warning,
            self::Throttled => BadgeTone::Danger,
        };
    }

    /** The filter of the journal's list this outcome falls under. */
    public function isRefusal(): bool
    {
        return self::Success !== $this;
    }
}
