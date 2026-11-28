<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A distribution is its author's (lot 1, step 1.8; DECISIONS 1.8c): they follow its progress,
 * relaunch a failed copy, move the date of its dossiers and withdraw it. Nobody else - an
 * administrator included: withdrawing deletes students' dossiers. The dossiers themselves are
 * DossierVoter's, which lets the teachers of the students' classes in.
 *
 * @extends Voter<string, Distribution>
 */
final class DistributionVoter extends Voter
{
    public const string MANAGE = 'DISTRIBUTION_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Distribution;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->isTeacher() && $subject->getAuthor() === $user;
    }
}
