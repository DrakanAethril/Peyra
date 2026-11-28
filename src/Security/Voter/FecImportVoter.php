<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Accounting\FecImportJob;
use App\Entity\Identity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A FEC import is its author's (step 1.10b): they follow its progress, relaunch or abandon it when
 * it stopped. Nobody else - an administrator included: the template it fills is the author's own.
 *
 * @extends Voter<string, FecImportJob>
 */
final class FecImportVoter extends Voter
{
    public const string MANAGE = 'FEC_IMPORT_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof FecImportJob;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->isTeacher() && $subject->getAuthor() === $user;
    }
}
