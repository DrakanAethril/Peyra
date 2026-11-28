<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Copy\DossierEraser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deleting a template of the library (design-ux.md § 6.2: a récapitulatif, then the template's
 * title typed). Its content, its files and its shares go; the students' and groups' dossiers made
 * from it keep theirs - they are copies - and lose only the link to it; its trail stays, with one
 * line more saying who deleted it. Who may: TemplateVoter::DELETE. Never automatic.
 */
final readonly class TemplateDeletion
{
    public function __construct(private DossierEraser $eraser, private AuditTrail $auditTrail, private EntityManagerInterface $entityManager)
    {
    }

    /** The sentence that refuses the deletion, or null once it is done. */
    public function delete(Dossier $template, string $typedTitle, User $by): ?string
    {
        if (!$template->isTemplate()) {
            throw new \LogicException('A template is deleted from the library.');
        }
        if (trim($typedTitle) !== $template->getLibraryTitle()) {
            return \sprintf('Tapez « %s » exactement pour supprimer ce modèle.', $template->getLibraryTitle());
        }
        $this->auditTrail->inDossier($template, AuditAction::TemplateDeleted, $by, \sprintf('Dossier modèle « %s » supprimé', $template->getLibraryTitle()), ['dossier' => $template->getId()]);
        $this->entityManager->flush();
        $this->eraser->deleteDossier($template);

        return null;
    }
}
