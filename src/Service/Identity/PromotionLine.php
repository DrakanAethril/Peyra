<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;

/** One student of a « passage de classe »: their choice and where it leads, or their enrolment already there. */
final readonly class PromotionLine
{
    public function __construct(
        public User $student,
        public PromotionChoice $choice,
        public ?SchoolClass $target,
        public ?Enrolment $alreadyEnrolled,
    ) {
    }

    public function isAlreadyEnrolled(): bool
    {
        return null !== $this->alreadyEnrolled;
    }

    public function isTicked(): bool
    {
        return PromotionChoice::NEXT === $this->choice->kind;
    }
}
