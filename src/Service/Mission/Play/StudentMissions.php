<?php

declare(strict_types=1);

namespace App\Service\Mission\Play;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Entity\Mission\MissionAssignment;
use App\Entity\Mission\MissionProgress;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Mission\MissionTaskCheck;
use App\Repository\Mission\MissionProgressRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Restore\RestorePoints;
use App\Service\Dossier\Restore\RestoreQueue;
use App\Service\Dossier\Restore\RestoreRefused;
use App\Service\Mission\CurrentMission;
use App\Service\Mission\MissionRefused;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The student's gestures on the mission their dossier plays (step 2.9b; design-ux.md § 2.3): « Tâche
 * terminée » and its undoing on a task the student ticks, while the mission is not handed in; and
 * « Rendre la mission », once: a « Rendu » restore point freezes the dossier as it is - what the
 * teacher corrects -, outside the limit of ten. Who may: a student who works in the dossier
 * (DossierVoter::EDIT, in the controller). Each gesture is a line of the dossier's trail.
 */
final readonly class StudentMissions
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CurrentMission $current,
        private MissionProgressRepository $progresses,
        private RestorePoints $restorePoints,
        private RestoreQueue $queue,
        private AuditTrail $auditTrail,
    ) {
    }

    /** @throws MissionRefused */
    public function declare(Dossier $dossier, string $key, bool $done, User $student): void
    {
        $assignment = $this->assignment($dossier);
        $task = null;
        foreach ($assignment->getMission()->getTasks() as $one) {
            if ($one['key'] === $key) {
                $task = $one;
            }
        }
        if (null === $task || MissionTaskCheck::Declared->value !== $task['check']) {
            throw MissionRefused::of(['task' => 'Cette tâche se coche d’elle-même, quand le travail est fait dans le dossier.']);
        }
        $progress = $this->progress($assignment, $dossier);
        if ($progress->isSubmitted()) {
            throw MissionRefused::of(['task' => 'La mission est rendue : ses tâches ne se cochent plus.']);
        }
        if ($progress->hasDeclared($key) === $done) {
            return;
        }
        $progress->declare($key, $done);
        $this->auditTrail->inDossier($dossier, AuditAction::MissionTaskDeclared, $student, \sprintf('Tâche « %s » %s', $task['title'], $done ? 'terminée' : 'rouverte'), ['mission' => $assignment->getMission()->getId(), 'task' => $key, 'done' => $done]);
        $this->entityManager->flush();
    }

    /** @throws MissionRefused */
    public function submit(Dossier $dossier, User $student, \DateTimeImmutable $now): RestorePoint
    {
        $assignment = $this->assignment($dossier);
        $progress = $this->progress($assignment, $dossier);
        if ($progress->isSubmitted()) {
            throw MissionRefused::of(['submit' => 'La mission est déjà rendue.']);
        }
        try {
            $this->restorePoints->expectFree($dossier);
        } catch (RestoreRefused $refused) {
            throw MissionRefused::of(['submit' => $refused->getMessage()]);
        }
        $name = mb_substr('Rendu · '.$assignment->getMission()->getTitle(), 0, RestorePoint::NAME_MAX);
        $point = $this->restorePoints->create($dossier, $name, RestorePointOrigin::Submitted, $student, $now);
        $progress->markSubmitted($student, $point, $now);
        $this->auditTrail->inDossier($dossier, AuditAction::MissionSubmitted, $student, \sprintf('Mission « %s » rendue', $assignment->getMission()->getTitle()), ['mission' => $assignment->getMission()->getId(), 'late' => $assignment->getDueAt() < $now]);
        $this->entityManager->flush();
        $this->queue->point($point);

        return $point;
    }

    private function assignment(Dossier $dossier): MissionAssignment
    {
        return $this->current->assignmentOf($dossier) ?? throw MissionRefused::of(['mission' => 'Ce dossier ne joue aucune mission.']);
    }

    private function progress(MissionAssignment $assignment, Dossier $dossier): MissionProgress
    {
        $progress = $this->progresses->findOneFor($assignment, $dossier);
        if (null === $progress) {
            $progress = new MissionProgress($assignment, $dossier);
            $this->entityManager->persist($progress);
        }

        return $progress;
    }
}
