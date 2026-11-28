<?php

declare(strict_types=1);

namespace App\Service\Dossier\Rights;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DossierMemberRepository;
use App\Service\Audit\AuditTrail;

/**
 * The one door that gives a group's members their profiles (Système d'information › Utilisateurs
 * et droits): each change is a line of the dossier's trail, signed by the teacher who made it. The
 * caller checks DossierVoter::SET_DATE - a teacher's gesture - and flushes.
 */
final readonly class DossierProfiles
{
    public function __construct(
        private DossierMemberRepository $members,
        private AuditTrail $auditTrail,
        private DossierRights $rights,
    ) {
    }

    /**
     * The profiles asked, by member id; a member the map does not name keeps theirs. Answers how
     * many changed.
     *
     * @param array<int, DossierProfile|null> $profiles
     */
    public function assign(Dossier $dossier, array $profiles, User $teacher): int
    {
        if (DossierKind::Group !== $dossier->getKind()) {
            throw new \LogicException('Only a group’s dossier has members with profiles.');
        }
        $changed = 0;
        foreach ($this->members->findByDossier($dossier) as $member) {
            $id = (int) $member->getId();
            if (!\array_key_exists($id, $profiles) || $profiles[$id] === $member->getProfile()) {
                continue;
            }
            $before = $member->getProfile();
            $member->assignProfile($profiles[$id]);
            $this->auditTrail->inDossier($dossier, AuditAction::MemberProfileChanged, $teacher, \sprintf(
                'Profil de %s : %s (auparavant : %s)',
                $member->getStudent()->getDisplayName(),
                self::named($profiles[$id]),
                self::named($before),
            ), ['member' => $member->getStudent()->getId(), 'profile' => $profiles[$id]?->value, 'before' => $before?->value]);
            ++$changed;
        }
        $this->rights->reset();

        return $changed;
    }

    private static function named(?DossierProfile $profile): string
    {
        return null === $profile ? 'sans profil' : mb_strtolower($profile->label());
    }
}
