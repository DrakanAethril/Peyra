<?php

declare(strict_types=1);

namespace App\Service\Dossier\Rights;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierMemberRepository;
use App\Repository\Dossier\DossierShareRepository;
use App\Service\Dossier\DossierHolders;

/**
 * Who enters a dossier and how far (Système d'information › Utilisateurs et droits, design-ux.md
 * § 3.2): the same facts DossierVoter decides on, read for a person - the student or the group's
 * members with their profiles, the teachers of their classes this year (every right, their gestures
 * signed with their name), the teacher who distributed it (reading); a template's author and the
 * colleagues it is shared with. Global reads only.
 */
final readonly class DossierUsers
{
    public function __construct(
        private DossierHolders $holders,
        private DossierMemberRepository $members,
        private DossierShareRepository $shares,
    ) {
    }

    /** @return list<DossierUserRow> the students first, then the teachers */
    public function of(Dossier $dossier): array
    {
        $rows = [];
        if (DossierKind::Template === $dossier->getKind()) {
            if (null !== $dossier->getOwner()) {
                $rows[] = new DossierUserRow($dossier->getOwner(), 'Auteur du modèle', 'Tous les droits');
            }
            foreach ($this->shares->findByDossier($dossier) as $share) {
                $rows[] = new DossierUserRow($share->getTeacher(), 'Partage', $share->canEdit() ? 'Modification' : 'Lecture');
            }

            return $rows;
        }
        if (DossierKind::Group === $dossier->getKind()) {
            foreach ($this->members->findByDossier($dossier) as $member) {
                $profile = $member->getProfile();
                $rows[] = new DossierUserRow($member->getStudent(), 'Membre du groupe', null === $profile ? 'Tous les droits (sans profil)' : $profile->label(), $member, $profile);
            }
        } elseif (null !== $dossier->getOwner()) {
            $rows[] = new DossierUserRow($dossier->getOwner(), 'Étudiant', 'Tous les droits');
        }
        $teachers = $this->holders->teachersThisYear($this->holders->of($dossier));
        foreach ($teachers as $teacher) {
            $rows[] = new DossierUserRow($teacher, 'Enseignant de la classe', 'Tous les droits, gestes signés de son nom');
        }
        $distributor = $dossier->getDistributedBy();
        if (null !== $distributor && !\in_array($distributor, $teachers, true)) {
            $rows[] = new DossierUserRow($distributor, 'A distribué le dossier', 'Lecture');
        }

        return $rows;
    }
}
