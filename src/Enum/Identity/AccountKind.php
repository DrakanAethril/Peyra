<?php

declare(strict_types=1);

namespace App\Enum\Identity;

/**
 * What an account is inside Peyra, decided by the entry rule at each sign-in: a student works in
 * the PGI, a teacher in the teacher space. Never both.
 */
enum AccountKind: string
{
    case Student = 'student';
    case Teacher = 'teacher';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Étudiant',
            self::Teacher => 'Enseignant',
        };
    }

    /** The Symfony role the kind grants (User::getRoles()). */
    public function role(): string
    {
        return match ($this) {
            self::Student => 'ROLE_STUDENT',
            self::Teacher => 'ROLE_TEACHER',
        };
    }
}
