<?php

declare(strict_types=1);

namespace App\Service\Assets\Depreciation;

/**
 * The days and months a depreciation counts (step 3.4; the demonstration's convention, plan
 * `donnees/demo/LISEZ-MOI.md`): a year of 360 days, months of 30 - the last day of a month counts
 * as its 30th, so that the 28th of February ends a month as the 31st of January does. Pure.
 */
final class DayCount
{
    /** The days from `$from` to `$to`, both included (0 when `$to` comes first). */
    public static function days(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return 0;
        }

        return ((int) $to->format('Y') - (int) $from->format('Y')) * 360
            + ((int) $to->format('n') - (int) $from->format('n')) * 30
            + self::day($to) - self::day($from) + 1;
    }

    /** The months from `$from`'s to `$to`'s, both included (0 when `$to` comes first). */
    public static function months(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12 + (int) $to->format('n') - (int) $from->format('n') + 1;

        return max(0, $months);
    }

    private static function day(\DateTimeImmutable $date): int
    {
        return $date->format('j') === $date->format('t') ? 30 : min(30, (int) $date->format('j'));
    }
}
