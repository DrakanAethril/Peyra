<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\AdministrationEvent;
use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use Doctrine\ORM\EntityManagerInterface;

/** Writes the administration journal; the caller flushes with the gesture itself. */
final readonly class AdministrationJournal
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(\DateTimeImmutable $at, User $actor, AdministrationAction $action, string $subjectLogin, string $subjectName, ?string $detail = null): void
    {
        $this->entityManager->persist(new AdministrationEvent($at, $actor, $action, $subjectLogin, $subjectName, $detail));
    }

    /** A gesture about a student: the subject is their login and name. */
    public function recordStudent(\DateTimeImmutable $at, User $actor, AdministrationAction $action, User $student, ?string $detail = null): void
    {
        $this->record($at, $actor, $action, $student->getLogin(), $student->getDisplayName(), $detail);
    }
}
