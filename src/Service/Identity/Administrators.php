<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use App\Repository\Identity\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administration › Administrateurs (design-ux.md § 3.6): an administrator designates a teacher,
 * and removes an administrator - never one of the bootstrap list (the environment sets them again
 * at each sign-in), never the last one. Every gesture is journaled.
 */
final readonly class Administrators
{
    public function __construct(
        private UserRepository $users,
        private EntryPolicy $policy,
        private AdministrationJournal $journal,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return string|null the refusal, null once designated */
    public function designate(User $teacher, User $by, \DateTimeImmutable $at): ?string
    {
        if (!$teacher->isTeacher()) {
            return 'Seul un enseignant peut être administrateur.';
        }
        if ($teacher->isAdministrator()) {
            return \sprintf('%s est déjà administrateur.', $teacher->getDisplayName());
        }
        $teacher->makeAdministrator($at, $by);
        $this->journal->record($at, $by, AdministrationAction::AdministratorDesignated, $teacher->getLogin(), $teacher->getDisplayName());
        $this->entityManager->flush();

        return null;
    }

    /** Why this administrator cannot be removed, or null when they can. */
    public function revocationRefusal(User $administrator): ?string
    {
        if (!$administrator->isAdministrator()) {
            return \sprintf('%s n’est pas administrateur.', $administrator->getDisplayName());
        }
        if ($this->isBootstrap($administrator)) {
            return 'Administrateur de la liste d’amorçage : il se retire dans la configuration du serveur, pas ici.';
        }
        if ($this->users->countAdministrators() <= 1) {
            return 'Le dernier administrateur ne se retire pas.';
        }

        return null;
    }

    /** @return string|null the refusal, null once removed */
    public function revoke(User $administrator, User $by, \DateTimeImmutable $at): ?string
    {
        $refusal = $this->revocationRefusal($administrator);
        if (null !== $refusal) {
            return $refusal;
        }
        $administrator->removeAdministrator();
        $this->journal->record($at, $by, AdministrationAction::AdministratorRevoked, $administrator->getLogin(), $administrator->getDisplayName());
        $this->entityManager->flush();

        return null;
    }

    public function isBootstrap(User $administrator): bool
    {
        return $this->policy->isBootstrapAdministrator($administrator->getLogin());
    }
}
