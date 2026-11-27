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

    public function record(\DateTimeImmutable $at, User $actor, AdministrationAction $action, string $subjectLogin, string $subjectName): void
    {
        $this->entityManager->persist(new AdministrationEvent($at, $actor, $action, $subjectLogin, $subjectName));
    }
}
