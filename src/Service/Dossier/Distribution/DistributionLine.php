<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Entity\Identity\User;
use App\Enum\Dossier\DossierProfile;

/** A student of the class in a distribution's steps: whether they already hold a copy of the template, whether they get one, their group and profile. */
final readonly class DistributionLine
{
    public function __construct(
        public User $student,
        public bool $holds,
        public bool $ticked,
        public int $group,
        public ?DossierProfile $profile,
    ) {
    }

    public function studentId(): int
    {
        return $this->student->getId() ?? throw new \LogicException('A saved student is planned.');
    }
}
