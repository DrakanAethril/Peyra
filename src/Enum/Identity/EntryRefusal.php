<?php

declare(strict_types=1);

namespace App\Enum\Identity;

/**
 * Why the entry rule turned an account away. The message is the one the sign-in page shows
 * (design-ux.md §§ 3.6 and 4.1): clear, and silent on the technical reason.
 */
enum EntryRefusal: string
{
    /** Neither a student nor a teacher of the BTS CG. */
    case NotInProgramme = 'not_in_programme';

    /** A teacher outside the CG groups, neither in the bootstrap list nor authorised by name. */
    case TeacherNotAuthorized = 'teacher_not_authorized';

    public function message(): string
    {
        return match ($this) {
            self::NotInProgramme => 'Peyra est réservé aux étudiants et aux enseignants du BTS CG. Votre compte n’en fait pas partie.',
            self::TeacherNotAuthorized => 'Votre compte n’est pas encore autorisé dans Peyra. Demandez à un administrateur de vous ajouter aux enseignants de la filière.',
        };
    }
}
