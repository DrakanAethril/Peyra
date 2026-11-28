<?php

declare(strict_types=1);

namespace App\Tests\Service\Mission\Play;

use App\Enum\Mission\MissionTaskStatus;
use App\Service\Mission\Play\MissionTaskStates;
use PHPUnit\Framework\TestCase;

/**
 * Where each task stands (step 2.9b): a declared task by the student's « Tâche terminée » only, a
 * detected one by the dossier only, the first not done is the current one.
 */
final class MissionTaskStatesTest extends TestCase
{
    private const array TASKS = [
        ['key' => 't1', 'title' => 'Contrôler les factures', 'instruction' => '', 'check' => 'declared'],
        ['key' => 't2', 'title' => 'Traiter les pièces', 'instruction' => '', 'check' => 'pieces'],
        ['key' => 't3', 'title' => 'Valider le brouillard', 'instruction' => '', 'check' => 'drafts'],
        ['key' => 't4', 'title' => 'Rédiger la note', 'instruction' => '', 'check' => 'declared'],
    ];

    public function testNothingDoneMakesTheFirstTaskCurrent(): void
    {
        $states = MissionTaskStates::of(self::TASKS, [], ['pieces' => false, 'drafts' => false]);

        self::assertSame([MissionTaskStatus::Current, MissionTaskStatus::ToDo, MissionTaskStatus::ToDo, MissionTaskStatus::ToDo], array_column($states, 'status'));
    }

    public function testDeclaredAndDetectedTasksAreDoneAndTheFirstLeftIsCurrent(): void
    {
        $states = MissionTaskStates::of(self::TASKS, ['t1'], ['pieces' => false, 'drafts' => true]);

        self::assertSame([true, false, true, false], array_column($states, 'done'));
        self::assertSame([false, true, false, false], array_column($states, 'current'));
    }

    public function testADetectedTaskIsNeverDoneByDeclaringIt(): void
    {
        $states = MissionTaskStates::of(self::TASKS, ['t2', 't3'], ['pieces' => false, 'drafts' => false]);

        self::assertSame([false, false, false, false], array_column($states, 'done'));
    }

    public function testEverythingDoneLeavesNoCurrentTask(): void
    {
        $states = MissionTaskStates::of(self::TASKS, ['t1', 't4'], ['pieces' => true, 'drafts' => true]);

        self::assertSame([MissionTaskStatus::Done, MissionTaskStatus::Done, MissionTaskStatus::Done, MissionTaskStatus::Done], array_column($states, 'status'));
        self::assertNotContains(true, array_column($states, 'current'));
    }
}
