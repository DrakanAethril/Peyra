<?php

declare(strict_types=1);

namespace App\Service\Mission\Play;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Mission\MissionTaskCheck;
use App\Repository\Mission\MissionAssignmentRepository;
use App\Repository\Mission\MissionProgressRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Mission\CurrentMission;
use App\Service\Shared\Assistance\StudentAssistanceLevels;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The mission a dossier plays, as its banner and panel read it (step 2.9b): the tasks and where each
 * stands, the declared ones from MissionProgress, the detected ones from the dossier (MissionDetections),
 * the due date, the level in force. Null without a mission. Read inside the dossier's context.
 */
final readonly class MissionPlay
{
    public function __construct(
        private CurrentMission $current,
        private MissionAssignmentRepository $assignments,
        private MissionProgressRepository $progresses,
        private MissionDetections $detections,
        private StudentAssistanceLevels $levels,
        private Security $security,
    ) {
    }

    public function view(Dossier $dossier, \DateTimeImmutable $now): ?MissionPlayView
    {
        $assignment = $this->current->assignmentOf($dossier);
        if (null === $assignment) {
            return null;
        }
        $mission = $assignment->getMission();
        $tasks = $mission->getTasks();
        $checks = array_values(array_unique(array_map(static fn (array $task): string => $task['check'], $tasks)));
        $progress = $this->progresses->findOneFor($assignment, $dossier);
        $me = $this->security->getUser();

        return new MissionPlayView(
            $mission,
            $assignment,
            $this->assignments->rankOf($assignment),
            MissionTaskStates::of($tasks, $progress?->getDeclaredTasks() ?? [], $this->detections->of($dossier, array_map(MissionTaskCheck::from(...), $checks))),
            $progress,
            $this->levels->for($dossier),
            null === $progress?->getSubmittedAt() && $assignment->getDueAt() < $now,
            $me instanceof User && $me->isStudent() && $this->security->isGranted(DossierVoter::EDIT, $dossier),
        );
    }
}
