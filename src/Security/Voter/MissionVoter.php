<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A mission of the library is its author's (step 2.9a): they open it, change it, duplicate it and
 * give it. Nobody else reads it - a colleague, an administrator, a student -: what it holds is the
 * answer to what the students will do. A refusal is a 404.
 *
 * @extends Voter<string, Mission>
 */
final class MissionVoter extends Voter
{
    public const string EDIT = 'MISSION_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EDIT === $attribute && $subject instanceof Mission;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->isTeacher() && $subject->getAuthor() === $user;
    }
}
