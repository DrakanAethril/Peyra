<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Mission\Mission;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Dossier\DossierKind;
use App\Enum\Mission\MissionModule;
use App\Service\Mission\MissionRules;
use App\Tests\Support\DossierFixtures;
use App\Tests\Support\IdentityFixtures;
use PHPUnit\Framework\TestCase;

/**
 * A task keeps its key for good (step 2.9b): what a student ticked follows it when the tasks move. A
 * new task draws a new key, a key held twice is drawn again; a task says what ticks it.
 */
final class MissionTasksTest extends TestCase
{
    public function testKeysAreGivenOnceAndFollowTheirTask(): void
    {
        $mission = self::mission();
        $mission->setTasks([['title' => 'Contrôler', 'instruction' => ''], ['title' => 'Valider', 'instruction' => '', 'check' => 'drafts']]);
        [$control, $validate] = array_column($mission->getTasks(), 'key');
        self::assertMatchesRegularExpression(Mission::TASK_KEY, $control);
        self::assertNotSame($control, $validate);
        self::assertSame(['declared', 'drafts'], array_column($mission->getTasks(), 'check'));

        // Moved, one added, the first removed, a key held twice.
        $mission->setTasks([
            ['key' => $validate, 'title' => 'Valider', 'instruction' => '', 'check' => 'drafts'],
            ['key' => '', 'title' => 'Rapprocher', 'instruction' => '', 'check' => 'statements'],
            ['key' => $validate, 'title' => 'Lettrer', 'instruction' => ''],
        ]);

        $keys = array_column($mission->getTasks(), 'key');
        self::assertSame($validate, $keys[0]);
        self::assertCount(3, array_unique($keys));
        self::assertNotContains($control, $keys);
        self::assertSame(['drafts', 'statements', 'declared'], array_column($mission->getTasks(), 'check'));
    }

    public function testTheTasksOfStepTwoNineAAreReadWithKeysAndDeclared(): void
    {
        $mission = self::mission();
        (new \ReflectionProperty(Mission::class, 'tasks'))->setValue($mission, [['title' => 'Contrôler', 'instruction' => ''], ['title' => 'Valider', 'instruction' => '']]);

        self::assertSame([['t1', 'declared'], ['t2', 'declared']], array_map(static fn (array $task): array => [$task['key'], $task['check']], $mission->getTasks()));
    }

    public function testATaskIsTickedByAKnownCheck(): void
    {
        self::assertSame([], MissionRules::tasks([['title' => 'Valider', 'instruction' => '', 'check' => 'drafts']]));
        self::assertSame(['task.0.check'], array_keys(MissionRules::tasks([['title' => 'Valider', 'instruction' => '', 'check' => 'guess']])));
    }

    private static function mission(): Mission
    {
        $teacher = IdentityFixtures::teacher(1, 'Sophie Durand');
        $template = new Dossier(DossierKind::Template, DossierFixtures::company(), new \DateTimeImmutable('2026-03-31'), $teacher, null, new \DateTimeImmutable('2026-11-28'), $teacher);

        return new Mission($template, $teacher, 'Janvier', [MissionModule::Documents], AssistanceLevel::Warnings, AccountingBehaviour::LetEnter, new \DateTimeImmutable('2026-11-28'));
    }
}
