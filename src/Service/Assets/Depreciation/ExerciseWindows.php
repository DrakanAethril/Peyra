<?php

declare(strict_types=1);

namespace App\Service\Assets\Depreciation;

/**
 * The exercises a plan is laid on (step 3.4): the dossier's own, then twelve months at a time
 * before the first and after the last, until the days asked are covered - a plan starts years
 * before the dossier and ends years after it. Without any exercise, calendar years. Pure.
 */
final class ExerciseWindows
{
    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $exercises the dossier's, in order
     *
     * @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public static function cover(array $exercises, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        if ([] === $exercises) {
            $year = $from->format('Y');
            $exercises = [['start' => new \DateTimeImmutable($year.'-01-01'), 'end' => new \DateTimeImmutable($year.'-12-31')]];
        }
        $windows = $exercises;
        while ($windows[0]['start'] > $from) {
            $start = $windows[0]['start'];
            array_unshift($windows, ['start' => $start->modify('-12 months'), 'end' => $start->modify('-1 day')]);
        }
        while ($windows[\count($windows) - 1]['end'] < $until) {
            $next = $windows[\count($windows) - 1]['end']->modify('+1 day');
            $windows[] = ['start' => $next, 'end' => $next->modify('+12 months')->modify('-1 day')];
        }

        return $windows;
    }
}
