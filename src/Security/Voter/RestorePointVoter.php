<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Who does what with a dossier's restore points (design-ux.md § 4.9; DECISIONS 1.8b):
 *
 * - POSE (a dossier): whoever works in it (DossierVoter::EDIT) - a point changes nothing;
 * - RETURN and DELETE (a point): whoever works in its dossier with the right on its settings
 *   (DossierRightVoter::SETTINGS: in a group's dossier, the chef comptable or a member without a
 *   profile; the student of their own dossier; its teachers) - a return undoes everybody's work.
 *   The « État de départ » a distribution posed is deleted by a teacher only: the reset reads it.
 *
 * Reading the list is DossierVoter::VIEW, asked by the dossier's route. A restore point is never
 * itself a dossier one poses points on.
 *
 * @extends Voter<string, Dossier|RestorePoint>
 */
final class RestorePointVoter extends Voter
{
    public const string POSE = 'RESTORE_POINT_POSE';
    public const string RETURN = 'RESTORE_POINT_RETURN';
    public const string DELETE = 'RESTORE_POINT_DELETE';

    public function __construct(private readonly DossierVoter $dossiers, private readonly DossierRightVoter $rights)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::POSE === $attribute && $subject instanceof Dossier)
            || (\in_array($attribute, [self::RETURN, self::DELETE], true) && $subject instanceof RestorePoint);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $dossier = $subject instanceof RestorePoint ? $subject->getDossier() : $subject;
        if (DossierKind::Snapshot === $dossier->getKind()
            || VoterInterface::ACCESS_GRANTED !== $this->dossiers->vote($token, $dossier, [DossierVoter::EDIT])) {
            return false;
        }

        if (self::DELETE === $attribute && $subject instanceof RestorePoint && !$subject->getOrigin()->countsAgainstLimit()) {
            $user = $token->getUser();
            if (!$user instanceof User || !$user->isTeacher()) {
                return false;
            }
        }

        return self::POSE === $attribute
            || VoterInterface::ACCESS_GRANTED === $this->rights->vote($token, $dossier, [DossierRightVoter::SETTINGS]);
    }
}
