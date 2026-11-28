<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * What a teacher does with a template of the library besides working in it (DossierVoter: VIEW,
 * EDIT, SET_DATE), lot 1 step 1.8:
 *
 * - SHARE: give or take back a colleague's reading or change - the author alone. A template of the
 *   common library is everybody's already: nobody shares it.
 * - DELETE: the author; for the common library, an administrator. A colleague it is shared with
 *   « en modification » changes it, never deletes it.
 * - DUPLICATE: whoever reads it may make their own copy of it.
 *
 * Anything else than a template: refused, read as a 404.
 *
 * @extends Voter<string, Dossier>
 */
final class TemplateVoter extends Voter
{
    public const string SHARE = 'TEMPLATE_SHARE';
    public const string DELETE = 'TEMPLATE_DELETE';
    public const string DUPLICATE = 'TEMPLATE_DUPLICATE';

    public function __construct(private readonly DossierVoter $dossiers)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::SHARE, self::DELETE, self::DUPLICATE], true) && $subject instanceof Dossier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isTeacher() || !$subject->isTemplate()) {
            return false;
        }
        $author = $subject->getOwner();

        return match ($attribute) {
            self::SHARE => $author === $user,
            self::DELETE => null === $author ? $user->isAdministrator() : $author === $user,
            default => Voter::ACCESS_GRANTED === $this->dossiers->vote($token, $subject, [DossierVoter::VIEW]),
        };
    }
}
