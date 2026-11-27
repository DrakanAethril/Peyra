<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;

/** A class as the lists show it: its effectif, its teachers, whether the reader teaches there. */
final readonly class ClassRow
{
    /** @param list<User> $teachers */
    public function __construct(
        public SchoolClass $schoolClass,
        public int $students,
        public array $teachers,
        public bool $mine,
    ) {
    }

    /** « Jean-Pierre Martin, Sophie Durand », or null when nobody teaches there yet. */
    public function teacherNames(): ?string
    {
        return [] === $this->teachers ? null : implode(', ', array_map(static fn (User $t): string => $t->getDisplayName(), $this->teachers));
    }
}
