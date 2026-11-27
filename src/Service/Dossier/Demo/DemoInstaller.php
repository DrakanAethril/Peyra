<?php

declare(strict_types=1);

namespace App\Service\Dossier\Demo;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Exercises;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The demonstration dossier, Menuiserie Vasseur SARL (plan README § 11), built from
 * `fixtures/demo/`: installed once as a template of the common library (app:demo:install, in
 * development and in production alike: it is the first template the référents get), and - in
 * development only, until the distribution of step 1.8 - built again as a student's own dossier
 * (app:dev:dossiers). Grows with each step.
 */
final readonly class DemoInstaller
{
    public const string DENOMINATION = 'Menuiserie Vasseur';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DossierRepository $dossiers,
        private Exercises $exercises,
        private AuditTrail $auditTrail,
        #[Autowire('%kernel.project_dir%/fixtures/demo')]
        private string $directory,
    ) {
    }

    /** The common library's demonstration template; null when it is already installed. */
    public function installTemplate(string $actor, \DateTimeImmutable $at): ?Dossier
    {
        if (null !== $this->dossiers->findCommonTemplateNamed(self::DENOMINATION)) {
            return null;
        }

        return $this->build(DossierKind::Template, null, null, $actor, $at, null);
    }

    public function findTemplate(): ?Dossier
    {
        return $this->dossiers->findCommonTemplateNamed(self::DENOMINATION);
    }

    /** The demonstration dossier as a student's own, made in a school year from the template. */
    public function buildForStudent(User $student, SchoolYear $year, Dossier $template, string $actor, \DateTimeImmutable $at): Dossier
    {
        return $this->build(DossierKind::Student, $student, $year, $actor, $at, $template);
    }

    private function build(DossierKind $kind, ?User $owner, ?SchoolYear $year, string $actor, \DateTimeImmutable $at, ?Dossier $template): Dossier
    {
        $file = DemoCompanyFile::read($this->directory.'/company.yaml');
        $dossier = new Dossier($kind, $file->company, $file->dossierDate, $owner, $year, $at, null);
        $dossier->comesFrom($template, null);
        $this->entityManager->persist($dossier);
        // An id first: the exercises enter the dossier's context.
        $this->entityManager->flush();
        foreach ($file->exercises as $exercise) {
            $this->exercises->open($dossier, $exercise['code'], $exercise['start'], $exercise['end'], $exercise['closed']);
        }
        $this->auditTrail->record(
            AuditAction::DossierCreated,
            $actor,
            'dossier',
            (string) $dossier->getId(),
            \sprintf('%s créé (%s)', $dossier->getName(), $kind->label()),
            ['dossier' => $dossier->getId(), 'kind' => $kind->value, 'owner' => $owner?->getLogin()],
        );
        $this->entityManager->flush();

        return $dossier;
    }
}
