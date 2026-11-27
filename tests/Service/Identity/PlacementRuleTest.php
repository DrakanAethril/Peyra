<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Enum\Identity\PlacementOutcome;
use App\Service\Identity\PlacementRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The placement at account creation (conception § 3 bis), case by case. */
final class PlacementRuleTest extends TestCase
{
    /** cg-1 → class 11, cg-2 → class 12, cg-alt → class 11 too (two groups for one class) */
    private const array MAPPING = ['cg-1' => 11, 'cg-2' => 12, 'cg-alt' => 11];

    /**
     * @param list<string>            $groups
     * @param array<string, int>|null $mapping
     */
    #[DataProvider('cases')]
    public function testDecides(array $groups, ?array $mapping, PlacementOutcome $outcome, ?int $classId): void
    {
        $decision = PlacementRule::decide($groups, $mapping);

        self::assertSame([$outcome, $classId], [$decision->outcome, $decision->classId]);
    }

    /** @return iterable<string, array{list<string>, array<string, int>|null, PlacementOutcome, ?int}> */
    public static function cases(): iterable
    {
        yield 'un groupe connu' => [['student', 'cg-1'], self::MAPPING, PlacementOutcome::Placed, 11];
        yield 'le groupe de 2e année' => [['student', 'cg-2'], self::MAPPING, PlacementOutcome::Placed, 12];
        yield 'deux groupes, une même classe' => [['student', 'cg-1', 'cg-alt'], self::MAPPING, PlacementOutcome::Placed, 11];
        yield 'deux groupes, deux classes' => [['student', 'cg-1', 'cg-2'], self::MAPPING, PlacementOutcome::ContradictoryGroups, null];
        yield 'aucun groupe associé' => [['student', 'sio-1'], self::MAPPING, PlacementOutcome::NoRecognisedGroup, null];
        yield 'aucun groupe du tout' => [[], self::MAPPING, PlacementOutcome::NoRecognisedGroup, null];
        yield 'année sans correspondance' => [['student', 'cg-1'], [], PlacementOutcome::NoRecognisedGroup, null];
        yield 'aucune année courante' => [['student', 'cg-1'], null, PlacementOutcome::NoCurrentYear, null];
        yield 'casse et espaces de l’annuaire' => [['Student', ' CG-1 '], self::MAPPING, PlacementOutcome::Placed, 11];
    }

    public function testAPlacedDecisionAlwaysNamesItsClass(): void
    {
        $this->expectException(\LogicException::class);
        \App\Service\Identity\PlacementDecision::unplaced(PlacementOutcome::Placed);
    }
}
