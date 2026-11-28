<?php

declare(strict_types=1);

namespace App\Service\Mission\Play;

use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAssignment;
use App\Entity\Mission\MissionProgress;
use App\Enum\Accounting\AssistanceLevel;

/**
 * What the mission banner and its panel draw in a dossier (step 2.9b; design-ui.md § 4.3,
 * design-ux.md § 2.3).
 *
 * @phpstan-import-type TaskState from MissionTaskStates
 */
final readonly class MissionPlayView
{
    /** @param list<TaskState> $tasks */
    public function __construct(
        public Mission $mission,
        public MissionAssignment $assignment,
        public int $rank,
        public array $tasks,
        public ?MissionProgress $progress,
        public AssistanceLevel $level,
        public bool $late,
        /** The person may tick the tasks and hand the mission in: a student who works in the dossier. */
        public bool $canPlay,
    ) {
    }

    public function doneCount(): int
    {
        return \count(array_filter($this->tasks, static fn (array $task): bool => $task['done']));
    }

    /** The rank (from 1) of the current task, null once every task is done. */
    public function currentRank(): ?int
    {
        foreach ($this->tasks as $i => $task) {
            if ($task['current']) {
                return $i + 1;
            }
        }

        return null;
    }

    /** @return TaskState|null */
    public function currentTask(): ?array
    {
        foreach ($this->tasks as $task) {
            if ($task['current']) {
                return $task;
            }
        }

        return null;
    }

    public function submitted(): bool
    {
        return null !== $this->progress && $this->progress->isSubmitted();
    }
}
