<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use App\Service\Identity\EntryPolicy;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads accounts by their stable directory id (User::getUserIdentifier()), so that a session
 * survives a renamed login. At each request it asks the entry rule again on what was read at the
 * last sign-in: an account the rule no longer lets in, or lets in as another kind, loses its
 * session (Symfony signs out a session whose user is not found).
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $users,
        private EntryPolicy $policy,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): User
    {
        return $this->users->findOneByDirectoryId($identifier)
            ?? throw new UserNotFoundException(\sprintf('No account for "%s".', $identifier));
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Unsupported user "%s".', $user::class));
        }
        $fresh = $this->loadUserByIdentifier($user->getUserIdentifier());
        $decision = $this->policy->decide($fresh->getLogin(), $fresh->getDirectoryId(), $fresh->getDirectoryGroups());
        if (!$decision->isAdmitted() || $decision->kind !== $fresh->getKind()) {
            throw new UserNotFoundException('The entry rule no longer lets this account in.');
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
