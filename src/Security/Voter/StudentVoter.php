<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Identity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who opens a student's record and changes their class (conception § 3 bis): every teacher of the
 * field. The subject must be a student: a teacher's account has no record here (404).
 *
 * @extends Voter<string, User>
 */
final class StudentVoter extends Voter
{
    public const string VIEW = 'STUDENT_VIEW';
    public const string PLACE = 'STUDENT_PLACE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::PLACE], true) && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->isTeacher() && $subject->isStudent();
    }
}
