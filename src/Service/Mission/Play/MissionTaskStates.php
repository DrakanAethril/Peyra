<?php

declare(strict_types=1);

namespace App\Service\Mission\Play;

use App\Enum\Mission\MissionTaskCheck;
use App\Enum\Mission\MissionTaskStatus;

/**
 * Where each task of a mission stands for a dossier (step 2.9b), pure: a task the student ticks is
 * done once declared, a task the dossier ticks once its detection holds - never the other way, so a
 * detected task is not declared by hand. The first task not done is the current one; the others
 * are done or to do (design-ux.md A11: « fait / en cours / à faire », never « juste / faux »).
 *
 * @phpstan-type TaskState array{key: string, title: string, instruction: string, check: MissionTaskCheck, done: bool, current: bool, status: MissionTaskStatus}
 */
final class MissionTaskStates
{
    /**
     * @param list<array{key: string, title: string, instruction: string, check: string}> $tasks    the mission's, in order
     * @param list<string>                                                                 $declared the keys the student declared done
     * @param array<string, bool>                                                          $detected by MissionTaskCheck value
     *
     * @return list<TaskState>
     */
    public static function of(array $tasks, array $declared, array $detected): array
    {
        $states = [];
        $current = false;
        foreach ($tasks as $task) {
            $check = MissionTaskCheck::tryFrom($task['check']) ?? MissionTaskCheck::Declared;
            $done = MissionTaskCheck::Declared === $check ? \in_array($task['key'], $declared, true) : ($detected[$check->value] ?? false);
            $isCurrent = !$done && !$current;
            $current = $current || $isCurrent;
            $states[] = ['key' => $task['key'], 'title' => $task['title'], 'instruction' => $task['instruction'], 'check' => $check, 'done' => $done, 'current' => $isCurrent, 'status' => $done ? MissionTaskStatus::Done : ($isCurrent ? MissionTaskStatus::Current : MissionTaskStatus::ToDo)];
        }

        return $states;
    }
}
