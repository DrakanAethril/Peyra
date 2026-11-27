<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Enum\Identity\PlacementOutcome;

/**
 * The placement at account creation (conception § 3 bis, decided 2026-11-27), a pure function: the
 * student goes into the one class their directory groups designate for the current year. With no
 * current year, no group that designates a class, or groups that designate two classes, they enter
 * without a class and wait in « À placer ». Applied once, when the account is created; never again.
 */
final class PlacementRule
{
    /**
     * @param list<string>             $groups       the student's directory groups
     * @param array<string, int>|null  $classByGroup the current year's mapping, group (lower case) → class id; null when no year is current
     */
    public static function decide(array $groups, ?array $classByGroup): PlacementDecision
    {
        if (null === $classByGroup) {
            return PlacementDecision::unplaced(PlacementOutcome::NoCurrentYear);
        }

        $classes = [];
        foreach ($groups as $group) {
            $classId = $classByGroup[mb_strtolower(trim($group))] ?? null;
            if (null !== $classId) {
                $classes[$classId] = true;
            }
        }

        return match (\count($classes)) {
            0 => PlacementDecision::unplaced(PlacementOutcome::NoRecognisedGroup),
            1 => PlacementDecision::placed(array_key_first($classes)),
            default => PlacementDecision::unplaced(PlacementOutcome::ContradictoryGroups),
        };
    }
}
