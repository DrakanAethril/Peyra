<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

/**
 * When a subscription's occurrences fall and what document each carries: a pure function over its
 * first month, its day, its number of occurrences and its document pattern - the list, the fiche,
 * the confirmation bar and the generation all read it.
 *
 * - The occurrence of rank n (from 1) falls in the (n-1)th month after the first, on its day; a day
 *   the month does not have is its last one (31 is always the last, 30 in February is the 28th or
 *   the 29th).
 * - The document is the pattern with {AAAA} the year, {MM} the month on two digits, {N} the rank.
 */
final class SubscriptionSchedule
{
    public const array TOKENS = ['{AAAA}', '{MM}', '{N}'];

    /** The date of the occurrence of rank n (from 1). */
    public static function dateOf(\DateTimeImmutable $firstMonth, int $day, int $rank): \DateTimeImmutable
    {
        if ($rank < 1) {
            throw new \InvalidArgumentException('An occurrence is ranked from 1.');
        }
        $month = $firstMonth->modify('first day of this month')->modify(\sprintf('+%d months', $rank - 1));
        $last = (int) $month->format('t');

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min(max($day, 1), $last));
    }

    /** « LOY-{AAAA}-{MM} » at the occurrence of rank 2 from February 2026: « LOY-2026-03 ». */
    public static function documentOf(string $pattern, \DateTimeImmutable $date, int $rank): string
    {
        return str_replace(self::TOKENS, [$date->format('Y'), $date->format('m'), (string) $rank], $pattern);
    }

    /**
     * Every occurrence, from the first.
     *
     * @return list<array{rank: int, date: \DateTimeImmutable, document: string}>
     */
    public static function occurrences(\DateTimeImmutable $firstMonth, int $day, int $count, string $pattern): array
    {
        $list = [];
        for ($rank = 1; $rank <= $count; ++$rank) {
            $date = self::dateOf($firstMonth, $day, $rank);
            $list[] = ['rank' => $rank, 'date' => $date, 'document' => self::documentOf($pattern, $date, $rank)];
        }

        return $list;
    }

    /**
     * The ranks still to generate whose date has come by `until` (the dossier's date), in order.
     *
     * @return list<int>
     */
    public static function dueUntil(\DateTimeImmutable $firstMonth, int $day, int $count, int $generated, \DateTimeImmutable $until): array
    {
        $due = [];
        for ($rank = $generated + 1; $rank <= $count; ++$rank) {
            if (self::dateOf($firstMonth, $day, $rank) > $until) {
                break;
            }
            $due[] = $rank;
        }

        return $due;
    }

    /** The date of the next occurrence to generate, or null when all are. */
    public static function next(\DateTimeImmutable $firstMonth, int $day, int $count, int $generated): ?\DateTimeImmutable
    {
        return $generated >= $count ? null : self::dateOf($firstMonth, $day, $generated + 1);
    }
}
