<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\AuthorizedTeacher;
use App\Entity\Identity\User;
use App\Enum\Identity\EntryBasis;

/**
 * One line of « Enseignants de la filière »: who may enter as a teacher, and why. Only a named
 * authorisation can be removed from this screen; the others follow the directory or the
 * environment.
 */
final readonly class FieldTeacherRow
{
    public function __construct(
        public ?string $displayName,
        public string $login,
        public EntryBasis $basis,
        public ?User $account,
        public ?AuthorizedTeacher $authorization,
        public ?string $groups,
    ) {
    }

    public function isRemovable(): bool
    {
        return null !== $this->authorization;
    }
}
