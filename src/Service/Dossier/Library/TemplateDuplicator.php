<?php

declare(strict_types=1);

namespace App\Service\Dossier\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\CopyKind;
use App\Enum\Dossier\DossierKind;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Copy\DossierCopier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Dupliquer » a template of the library, and « Nouveau dossier modèle › Copie d'un modèle »
 * (design-ux.md § 3.3): the teacher's own template, a deep copy of one they read - its content as
 * it is handed out (CopyKind::Template: nobody's pending work), its date, its card under the title
 * they give (the original's, « (copie) », by default). Its origin names the template it comes
 * from; its shares are not copied: the copy is nobody's but its author's.
 */
final readonly class TemplateDuplicator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DossierCopier $copier,
        private SchoolYearRepository $years,
        private AuditTrail $auditTrail,
    ) {
    }

    /** The title a copy takes when none is given. */
    public static function copyTitle(Dossier $template): string
    {
        return mb_substr($template->getLibraryTitle(), 0, TemplateCards::TITLE_MAX - 9).' (copie)';
    }

    public function duplicate(Dossier $template, User $by, \DateTimeImmutable $at, string $title = ''): Dossier
    {
        if (!$template->isTemplate() || !$by->isTeacher()) {
            throw new \LogicException('A teacher duplicates a template.');
        }
        $title = mb_substr('' === trim($title) ? self::copyTitle($template) : trim($title), 0, TemplateCards::TITLE_MAX);

        return $this->entityManager->wrapInTransaction(function () use ($template, $by, $at, $title): Dossier {
            $copy = new Dossier(DossierKind::Template, $template->getCompany(), $template->getDossierDate(), $by, $this->years->findCurrent(), $at, $by);
            $copy->comesFrom($template, null);
            $copy->describeInLibrary($title, $template->getLibraryDescription(), $template->getLibraryLevel(), $template->getLibraryProcesses());
            $this->entityManager->persist($copy);
            $this->entityManager->flush();
            $report = $this->copier->copy($template, $copy, CopyKind::Template);
            $this->auditTrail->inDossier($copy, AuditAction::DossierCreated, $by, \sprintf('Dossier modèle « %s » créé, copie de « %s »', $title, $template->getLibraryTitle()), [
                'dossier' => $copy->getId(),
                'kind' => DossierKind::Template->value,
                'source' => $template->getId(),
                'rows' => $report->rowCount(),
                'files' => $report->files,
            ]);
            $this->entityManager->flush();

            return $copy;
        });
    }
}
