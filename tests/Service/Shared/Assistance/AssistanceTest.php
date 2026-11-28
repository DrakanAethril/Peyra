<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Assistance;

use App\Enum\Accounting\AssistanceLevel;
use App\Service\Shared\Assistance\Assistance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each level shows, proposes and stops (design-ux.md § 3.2, the « Assistance » column of the
 * screens built so far), level by level - read in the table, not from the code.
 */
final class AssistanceTest extends TestCase
{
    /**
     * @return iterable<string, array{AssistanceLevel, array<string, bool>}>
     */
    public static function levels(): iterable
    {
        yield 'Guidé' => [AssistanceLevel::Guided, [
            'runsControls' => true, 'blocks' => true, 'allowsPartialLettering' => false, 'proposesLettering' => true,
            'opensGuidedEntry' => true, 'refusesAccountOutsideChart' => true, 'signalsAccountOutsideChart' => true,
        ]];
        yield 'Contrôles bloquants' => [AssistanceLevel::Blocking, [
            'runsControls' => true, 'blocks' => true, 'allowsPartialLettering' => false, 'proposesLettering' => false,
            'opensGuidedEntry' => true, 'refusesAccountOutsideChart' => true, 'signalsAccountOutsideChart' => true,
        ]];
        yield 'Avertissements' => [AssistanceLevel::Warnings, [
            'runsControls' => true, 'blocks' => false, 'allowsPartialLettering' => true, 'proposesLettering' => false,
            'opensGuidedEntry' => false, 'refusesAccountOutsideChart' => false, 'signalsAccountOutsideChart' => true,
        ]];
        yield 'Aucun filet' => [AssistanceLevel::None, [
            'runsControls' => false, 'blocks' => false, 'allowsPartialLettering' => true, 'proposesLettering' => false,
            'opensGuidedEntry' => false, 'refusesAccountOutsideChart' => false, 'signalsAccountOutsideChart' => false,
        ]];
    }

    /** @param array<string, bool> $expected */
    #[DataProvider('levels')]
    public function testEachLevelDecidesWhatTheTableSays(AssistanceLevel $level, array $expected): void
    {
        $assistance = Assistance::at($level);

        self::assertSame($expected, [
            'runsControls' => $assistance->runsControls(),
            'blocks' => $assistance->blocks(),
            'allowsPartialLettering' => $assistance->allowsPartialLettering(),
            'proposesLettering' => $assistance->proposesLettering(),
            'opensGuidedEntry' => $assistance->opensGuidedEntry(false),
            'refusesAccountOutsideChart' => $assistance->refusesAccountOutsideChart(),
            'signalsAccountOutsideChart' => $assistance->signalsAccountOutsideChart(),
        ]);
        self::assertSame($level, $assistance->level);
    }

    public function testATeacherAlwaysHasTheGuidedEntry(): void
    {
        foreach (AssistanceLevel::cases() as $level) {
            self::assertTrue(Assistance::at($level)->opensGuidedEntry(true), $level->label());
        }
    }
}
