<?php

declare(strict_types=1);

namespace App\Service\Mission;

use App\Enum\Mission\MissionModule;

/**
 * What a mission's tabs must hold (step 2.9a), pure: each check answers its faults by field, empty
 * when the values may be written. Texts are trimmed by the caller's reader.
 */
final class MissionRules
{
    public const int TITLE_MAX = 120;
    public const int STATEMENT_MAX = 6000;
    public const int TASKS_MAX = 20;
    public const int TASK_TITLE_MAX = 160;
    public const int TASK_INSTRUCTION_MAX = 2000;

    /**
     * @param list<string> $modules MissionModule values as posted
     *
     * @return array<string, string>
     */
    public static function general(string $title, string $statement, array $modules): array
    {
        $errors = [];
        if ('' === $title) {
            $errors['title'] = 'Donnez un titre à la mission.';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = \sprintf('Un titre tient en %d caractères.', self::TITLE_MAX);
        }
        if (mb_strlen($statement) > self::STATEMENT_MAX) {
            $errors['statement'] = \sprintf('L’énoncé tient en %d caractères.', self::STATEMENT_MAX);
        }
        $known = array_filter($modules, static fn (string $module): bool => null !== MissionModule::tryFrom($module));
        if ([] === $known) {
            $errors['modules'] = 'Ouvrez au moins un module : le rail de l’étudiant ne montre que ceux-là.';
        } elseif (\count($known) !== \count($modules)) {
            $errors['modules'] = 'Un module coché n’existe pas.';
        }

        return $errors;
    }

    /**
     * Each task has its title; the instruction is optional. Faults under `task.<i>.title` /
     * `task.<i>.instruction`, or `tasks`.
     *
     * @param list<array{title: string, instruction: string}> $tasks
     *
     * @return array<string, string>
     */
    public static function tasks(array $tasks): array
    {
        $errors = [];
        if (\count($tasks) > self::TASKS_MAX) {
            $errors['tasks'] = \sprintf('Une mission compte au plus %d tâches.', self::TASKS_MAX);
        }
        foreach ($tasks as $i => $task) {
            if ('' === $task['title']) {
                $errors['task.'.$i.'.title'] = 'Donnez un intitulé à la tâche, ou retirez-la.';
            } elseif (mb_strlen($task['title']) > self::TASK_TITLE_MAX) {
                $errors['task.'.$i.'.title'] = \sprintf('Un intitulé tient en %d caractères.', self::TASK_TITLE_MAX);
            }
            if (mb_strlen($task['instruction']) > self::TASK_INSTRUCTION_MAX) {
                $errors['task.'.$i.'.instruction'] = \sprintf('Une consigne tient en %d caractères.', self::TASK_INSTRUCTION_MAX);
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $codes the activities ticked
     * @param list<string> $known the référentiel's codes
     *
     * @return array<string, string>
     */
    public static function activities(array $codes, array $known): array
    {
        foreach ($codes as $code) {
            if (!\in_array($code, $known, true)) {
                return ['activities' => \sprintf('L’activité « %s » n’est pas au référentiel.', $code)];
            }
        }

        return [];
    }
}
