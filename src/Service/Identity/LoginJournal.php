<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\LoginEvent;
use App\Entity\Identity\User;
use App\Enum\Identity\LoginOutcome;
use Doctrine\ORM\EntityManagerInterface;

/** Writes the sign-in journal (Administration › Journal des connexions), one line per attempt. */
final readonly class LoginJournal
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(\DateTimeImmutable $at, string $login, LoginOutcome $outcome, ?User $user = null, ?string $displayName = null, ?string $ip = null): void
    {
        $this->entityManager->persist(new LoginEvent($at, $login, $outcome, $user, $displayName ?? $user?->getDisplayName(), $ip));
        $this->entityManager->flush();
    }
}
