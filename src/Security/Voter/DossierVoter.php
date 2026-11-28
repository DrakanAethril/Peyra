<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierShareRepository;
use App\Service\Dossier\DossierHolders;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who enters a dossier (conception §§ 3 bis and 4, lot 1 step 1.1), and nobody else -
 * administrators included for the students' dossiers outside their classes:
 *
 * - a student's or a group's dossier: its student, or the group's members (VIEW, EDIT); the
 *   teachers of a class one of them is enrolled in this school year (VIEW, EDIT, SET_DATE), every
 *   year's dossiers of that student; the teacher who distributed it, who keeps reading it (VIEW);
 * - a template: its author (everything) and the colleagues it is shared with (VIEW, plus EDIT and
 *   SET_DATE when shared for change); a template of the common library (no author) is read by
 *   every teacher and changed by the administrators;
 * - a restore point is never opened as a dossier: one goes back to it from its dossier.
 *
 * VIEW opens it, EDIT works in it, SET_DATE moves its date - a teacher's gesture, never a
 * student's. A refusal reads as a 404 (App\Security\DossierRouteListener).
 *
 * @extends Voter<string, Dossier>
 */
final class DossierVoter extends Voter
{
    public const string VIEW = 'DOSSIER_VIEW';
    public const string EDIT = 'DOSSIER_EDIT';
    public const string SET_DATE = 'DOSSIER_SET_DATE';

    public function __construct(private readonly DossierHolders $holders, private readonly DossierShareRepository $shares)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::SET_DATE], true) && $subject instanceof Dossier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($subject->getKind()) {
            DossierKind::Template => $this->template($attribute, $subject, $user),
            DossierKind::Student, DossierKind::Group => $this->held($attribute, $subject, $user),
            DossierKind::Snapshot => false,
        };
    }

    private function template(string $attribute, Dossier $template, User $user): bool
    {
        // A template a FEC is still being written into is nobody's to open (step 1.10b).
        if (!$user->isTeacher() || $template->isImporting()) {
            return false;
        }
        $author = $template->getOwner();
        if (null === $author) {
            return self::VIEW === $attribute || $user->isAdministrator();
        }
        if ($author === $user) {
            return true;
        }
        $share = $this->shares->findOneFor($template, $user);

        return null !== $share && (self::VIEW === $attribute || $share->canEdit());
    }

    private function held(string $attribute, Dossier $dossier, User $user): bool
    {
        $holders = $this->holders->of($dossier);
        if ($user->isStudent()) {
            return self::SET_DATE !== $attribute && \in_array($user, $holders, true);
        }
        if (!$user->isTeacher()) {
            return false;
        }
        if ($this->holders->teachesThisYear($user, $holders)) {
            return true;
        }

        return self::VIEW === $attribute && $dossier->getDistributedBy() === $user;
    }
}
