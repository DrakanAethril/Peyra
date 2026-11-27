<?php

declare(strict_types=1);

namespace App\Service\Dossier;

/**
 * How an exercise is cut into periods (conception § 5): one per calendar month, the first starting
 * on the exercise's first day and the last ending on its last - a pure function over days. An
 * exercise runs from 1 to 24 months (a first exercise may be longer than a year, never more than
 * two), its first day before its last.
 */
final class PeriodPlan
{
    public const int MAX_MONTHS = 24;

    /**
     * @return list<array{\DateTimeImmutable, \DateTimeImmutable}> the bounds of each period, in order
     *
     * @throws \InvalidArgumentException when the bounds make no exercise
     */
    public static function monthly(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($end < $start) {
            throw new \InvalidArgumentException('An exercise ends after it starts.');
        }
        $periods = [];
        $first = $start;
        while ($first <= $end) {
            $monthEnd = $first->modify('last day of this month');
            $last = $monthEnd < $end ? $monthEnd : $end;
            $periods[] = [$first, $last];
            $first = $last->modify('+1 day');
        }
        if (\count($periods) > self::MAX_MONTHS) {
            throw new \InvalidArgumentException('An exercise lasts 24 months at most.');
        }

        return $periods;
    }
}
