<?php

declare(strict_types=1);

namespace App\Service\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAssignment;
use App\Enum\Audit\AuditAction;
use App\Repository\Mission\MissionAssignmentRepository;
use App\Service\Audit\AuditTrail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Donner la mission » (step 2.9a; design-ux.md § 4.2): a mission given to a distribution of its
 * template, due back at a real date after now - from then on its settings are the distribution's
 * dossiers' (CurrentMission). Once per distribution; a later mission given to it takes over.
 */
final readonly class MissionAssignments
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MissionAssignmentRepository $assignments,
        private AuditTrail $auditTrail,
        private CurrentMission $current,
    ) {
    }

    public function give(Mission $mission, ?Distribution $distribution, ?\DateTimeImmutable $dueAt, User $actor, \DateTimeImmutable $now): MissionAssignment
    {
        $errors = [];
        if (null === $distribution) {
            $errors['distribution'] = 'Choisissez la distribution à qui donner la mission.';
        } elseif (null === $mission->getTemplate() || $distribution->getTemplate() !== $mission->getTemplate()) {
            $errors['distribution'] = 'Cette distribution n’a pas été faite depuis le dossier modèle de la mission.';
        } elseif (null !== $this->assignments->findOneFor($mission, $distribution)) {
            $errors['distribution'] = 'La mission est déjà donnée à cette distribution.';
        }
        if (null === $dueAt) {
            $errors['dueAt'] = 'Indiquez la date de rendu.';
        } elseif ($dueAt <= $now) {
            $errors['dueAt'] = 'La date de rendu est à venir.';
        }
        if ([] !== $errors || null === $distribution || null === $dueAt) {
            throw MissionRefused::of($errors);
        }
        $assignment = new MissionAssignment($mission, $distribution, $actor, $now, $dueAt);
        $this->entityManager->persist($assignment);
        $this->auditTrail->record(AuditAction::MissionGiven, $actor, 'mission', (string) $mission->getId(), \sprintf(
            'Mission « %s » donnée à « %s » (%s), à rendre le %s',
            $mission->getTitle(),
            $distribution->getTitle(),
            $distribution->getSchoolClass()->getName(),
            $dueAt->format('d/m/Y H:i'),
        ), ['distribution' => $distribution->getId()]);
        $this->entityManager->flush();
        $this->current->reset();

        return $assignment;
    }
}
