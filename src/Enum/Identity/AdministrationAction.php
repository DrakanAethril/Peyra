<?php

declare(strict_types=1);

namespace App\Enum\Identity;

/** A gesture of the administration screens, written to the administration journal (design-ux.md § 3.6). */
enum AdministrationAction: string
{
    case TeacherAuthorized = 'teacher_authorized';
    case TeacherAuthorizationRevoked = 'teacher_authorization_revoked';
    case AdministratorDesignated = 'administrator_designated';
    case AdministratorRevoked = 'administrator_revoked';

    public function label(): string
    {
        return match ($this) {
            self::TeacherAuthorized => 'Enseignant ajouté à la filière',
            self::TeacherAuthorizationRevoked => 'Enseignant retiré de la filière',
            self::AdministratorDesignated => 'Administrateur désigné',
            self::AdministratorRevoked => 'Administrateur retiré',
        };
    }
}
