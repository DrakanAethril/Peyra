<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds or creates the account of a person the entry rule let in (just-in-time provisioning, as in
 * Formatool), keyed on the stable id: a renamed login finds its account and gives it the new
 * login. A login of the bootstrap list gets the administrator flag at each sign-in.
 */
final readonly class AccountProvisioner
{
    public function __construct(
        private UserRepository $users,
        private EntryPolicy $policy,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function provision(DirectoryPerson $person, EntryDecision $decision, \DateTimeImmutable $at): User
    {
        if (!$decision->isAdmitted()) {
            throw new \LogicException('A refused person gets no account.');
        }

        $user = $this->users->findOneByDirectoryId($person->stableId);
        if (null === $user) {
            $user = new User($person->stableId, $person->login, $person->displayName, $decision->kind, $decision->basis, $person->groups, $at);
            $user->setEmail($person->email);
            $this->entityManager->persist($user);
        } else {
            $user->refreshFromDirectory($person->login, $person->displayName, $person->email, $person->groups, $decision->kind, $decision->basis);
        }

        if ($user->isTeacher() && $this->policy->isBootstrapAdministrator($person->login)) {
            $user->makeAdministrator($at, null);
        }
        $user->recordLogin($at);
        $this->entityManager->flush();

        return $user;
    }
}
