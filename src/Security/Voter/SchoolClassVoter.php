<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Service\Identity\Enrolments;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who reads a class and who places students in it (conception § 3 bis): every teacher of the field
 * reads every class, and places students in the classes of the current year or a later one - « un
 * enseignant de la filière ou l'administrateur modifie le placement ». The classes of past years
 * are history: read, never changed. A student reads no class here.
 *
 * @extends Voter<string, SchoolClass>
 */
final class SchoolClassVoter extends Voter
{
    public const string VIEW = 'CLASS_VIEW';
    public const string PLACE = 'CLASS_PLACE';

    public function __construct(private readonly Enrolments $enrolments)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::PLACE], true) && $subject instanceof SchoolClass;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isTeacher()) {
            return false;
        }

        return self::VIEW === $attribute || !$this->enrolments->isHistory($subject);
    }
}
