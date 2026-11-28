<?php

declare(strict_types=1);

namespace App\Service\Shared\Assistance;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierMemberRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Mission\CurrentMission;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The level a dossier is worked at now (conception § 4, « Niveau d'assistance : par mission,
 * surchargeable par étudiant »): the student's own level when they have one, else the level of the
 * mission the dossier plays (CurrentMission, step 2.9a), else « Avertissements ». Whose own level: a student's dossier, its
 * student's, whoever opens it (a teacher sees what the student works with); a group's dossier, the
 * member working in it; a template, nobody's.
 */
final readonly class StudentAssistanceLevels implements AssistanceLevels
{
    public const AssistanceLevel DEFAULT = AssistanceLevel::Warnings;

    public function __construct(
        private Security $security,
        private DossierMemberRepository $members,
        private CurrentMission $missions,
    ) {
    }

    /** @phpstan-impure it reads who is working, whose level may change between two calls */
    public function for(Dossier $dossier): AssistanceLevel
    {
        return $this->studentOf($dossier)?->getAssistanceLevel() ?? $this->missions->of($dossier)?->getAssistanceLevel() ?? self::DEFAULT;
    }

    private function studentOf(Dossier $dossier): ?User
    {
        if (DossierKind::Student === $dossier->getKind()) {
            return $dossier->getOwner();
        }
        $me = $this->security->getUser();
        if (DossierKind::Group === $dossier->getKind() && $me instanceof User && $me->isStudent() && $this->members->isMember($dossier, $me)) {
            return $me;
        }

        return null;
    }
}
