<?php

declare(strict_types=1);

namespace App\Service\Dossier\Rights;

use App\Entity\Dossier\DossierMember;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierProfile;

/**
 * A person who enters a dossier, as Utilisateurs et droits lists them: what they are there (the
 * student, a member of the group, a teacher of their class, the author of a template…), their
 * profile in a group's dossier, and what that gives them in words.
 */
final readonly class DossierUserRow
{
    public function __construct(
        public User $user,
        public string $role,
        public string $rights,
        public ?DossierMember $member = null,
        public ?DossierProfile $profile = null,
    ) {
    }
}
