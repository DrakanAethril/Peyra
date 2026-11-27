<?php

declare(strict_types=1);

namespace App\Enum\Identity;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Why the entry rule let an account in (README of the plan, § 9). A teacher is let in by the first
 * that applies, in the order of the cases.
 */
enum EntryBasis: string implements HasBadge
{
    /** A student, member of a group of PEYRA_STUDENT_GROUPS. */
    case StudentGroup = 'student_group';

    /** A teacher, member of a group of PEYRA_TEACHER_GROUPS. */
    case TeacherGroup = 'teacher_group';

    /** A teacher whose login is in PEYRA_ADMIN_LOGINS, the bootstrap list. */
    case BootstrapList = 'bootstrap_list';

    /** A teacher an administrator authorised by name (Administration › Enseignants de la filière). */
    case NamedAuthorization = 'named_authorization';

    public function label(): string
    {
        return match ($this) {
            self::StudentGroup => 'Groupe de l’annuaire',
            self::TeacherGroup => 'Groupe de l’annuaire',
            self::BootstrapList => 'Liste d’amorçage',
            self::NamedAuthorization => 'Autorisé nommément',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::StudentGroup, self::TeacherGroup => BadgeTone::Neutral,
            self::BootstrapList, self::NamedAuthorization => BadgeTone::Info,
        };
    }
}
