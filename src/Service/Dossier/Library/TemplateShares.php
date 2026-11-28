<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Repository\Dossier\DossierShareRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of a template's shares (conception § 4, « partageable entre enseignants, lecture ou
 * modification »): the author gives a colleague the reading or the change of it, changes that
 * right, takes it back - each gesture a line of the template's trail. Who may: TemplateVoter::SHARE.
 * A share says who enters, not what the dossier holds: never copied with it (DECISIONS 1.1).
 */
final readonly class TemplateShares
{
    public function __construct(private DossierShareRepository $shares, private EntityManagerInterface $entityManager, private AuditTrail $auditTrail)
    {
    }

    /** Shares the template, or changes the right of a colleague it is already shared with; a refusal's sentence otherwise. */
    public function share(Dossier $template, ?User $colleague, bool $canEdit, User $by, \DateTimeImmutable $at): ?string
    {
        if (null === $colleague || !$colleague->isTeacher()) {
            return 'Choisissez un enseignant dans la liste.';
        }
        if (!$template->isTemplate() || null === $template->getOwner()) {
            return 'Ce modèle ne se partage pas.';
        }
        if ($colleague === $template->getOwner()) {
            return 'Ce modèle est déjà le vôtre.';
        }
        $right = $canEdit ? 'modification' : 'lecture';
        $share = $this->shares->findOneFor($template, $colleague);
        if (null !== $share && $share->canEdit() === $canEdit) {
            return null;
        }
        if (null === $share) {
            $this->entityManager->persist(new DossierShare($template, $colleague, $canEdit, $at));
            $summary = \sprintf('Modèle « %s » partagé avec %s (%s)', $template->getLibraryTitle(), $colleague->getDisplayName(), $right);
        } else {
            $share->changeRight($canEdit);
            $summary = \sprintf('Partage du modèle « %s » avec %s passé en %s', $template->getLibraryTitle(), $colleague->getDisplayName(), $right);
        }
        $this->auditTrail->inDossier($template, AuditAction::TemplateShared, $by, $summary, ['teacher' => $colleague->getLogin(), 'canEdit' => $canEdit]);

        return null;
    }

    public function revoke(DossierShare $share, User $by): void
    {
        $template = $share->getDossier();
        $this->entityManager->remove($share);
        $this->auditTrail->inDossier($template, AuditAction::TemplateUnshared, $by, \sprintf('Partage du modèle « %s » avec %s retiré', $template->getLibraryTitle(), $share->getTeacher()->getDisplayName()), ['teacher' => $share->getTeacher()->getLogin()]);
    }
}
