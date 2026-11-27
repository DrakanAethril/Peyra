<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Subscription;

use App\Service\Accounting\Subscription\SubscriptionSchedule;
use PHPUnit\Framework\TestCase;

/**
 * When a subscription's occurrences fall and what they are called: the dates worked out by hand on
 * a calendar (2026 is not a leap year, 2028 is), the documents spelled out.
 */
final class SubscriptionScheduleTest extends TestCase
{
    public function testTheOccurrenceOfRankNFallsOnItsDayOfTheNthMonth(): void
    {
        $february = new \DateTimeImmutable('2026-02-01');
        self::assertSame('2026-02-01', SubscriptionSchedule::dateOf($february, 1, 1)->format('Y-m-d'));
        self::assertSame('2026-03-05', SubscriptionSchedule::dateOf($february, 5, 2)->format('Y-m-d'));
        self::assertSame('2026-12-01', SubscriptionSchedule::dateOf($february, 1, 11)->format('Y-m-d'));
        self::assertSame('2027-01-05', SubscriptionSchedule::dateOf($february, 5, 12)->format('Y-m-d'));
        self::assertSame('2028-08-05', SubscriptionSchedule::dateOf($february, 5, 31)->format('Y-m-d'));
    }

    public function testADayTheMonthDoesNotHaveIsItsLast(): void
    {
        $january = new \DateTimeImmutable('2026-01-01');
        self::assertSame('2026-01-31', SubscriptionSchedule::dateOf($january, 31, 1)->format('Y-m-d'));
        self::assertSame('2026-02-28', SubscriptionSchedule::dateOf($january, 31, 2)->format('Y-m-d'));
        self::assertSame('2026-02-28', SubscriptionSchedule::dateOf($january, 30, 2)->format('Y-m-d'));
        self::assertSame('2026-04-30', SubscriptionSchedule::dateOf($january, 31, 4)->format('Y-m-d'));
        self::assertSame('2026-04-30', SubscriptionSchedule::dateOf($january, 30, 4)->format('Y-m-d'));
        self::assertSame('2028-02-29', SubscriptionSchedule::dateOf(new \DateTimeImmutable('2028-02-01'), 30, 1)->format('Y-m-d'));
    }

    public function testTheDocumentFillsTheYearTheMonthAndTheRank(): void
    {
        $date = new \DateTimeImmutable('2026-03-05');
        self::assertSame('LOY-2026-03', SubscriptionSchedule::documentOf('LOY-{AAAA}-{MM}', $date, 2));
        self::assertSame('LBE 19/48', SubscriptionSchedule::documentOf('LBE {N}/48', $date, 19));
        self::assertSame('AB-03-7-2026', SubscriptionSchedule::documentOf('AB-{MM}-{N}-{AAAA}', $date, 7));
    }

    public function testEveryOccurrenceIsListed(): void
    {
        $list = SubscriptionSchedule::occurrences(new \DateTimeImmutable('2026-11-01'), 31, 3, 'L{N}');
        self::assertSame(
            [[1, '2026-11-30', 'L1'], [2, '2026-12-31', 'L2'], [3, '2027-01-31', 'L3']],
            array_map(static fn (array $one): array => [$one['rank'], $one['date']->format('Y-m-d'), $one['document']], $list),
        );
    }

    public function testTheOccurrencesDueAreThoseNotGeneratedWhoseDateHasCome(): void
    {
        $january = new \DateTimeImmutable('2026-01-01');
        // The 1st of January, February and March by 15/03; the 1st of April is not yet.
        self::assertSame([1, 2, 3], SubscriptionSchedule::dueUntil($january, 1, 12, 0, new \DateTimeImmutable('2026-03-15')));
        self::assertSame([3], SubscriptionSchedule::dueUntil($january, 1, 12, 2, new \DateTimeImmutable('2026-03-15')));
        // The 20th of March has not come on the 15th.
        self::assertSame([1, 2], SubscriptionSchedule::dueUntil($january, 20, 12, 0, new \DateTimeImmutable('2026-03-15')));
        self::assertSame([3], SubscriptionSchedule::dueUntil($january, 20, 12, 2, new \DateTimeImmutable('2026-03-20')));
        // Two occurrences only, both made.
        self::assertSame([], SubscriptionSchedule::dueUntil($january, 1, 2, 2, new \DateTimeImmutable('2026-12-31')));
        self::assertSame([], SubscriptionSchedule::dueUntil($january, 1, 12, 0, new \DateTimeImmutable('2025-12-31')));
    }

    public function testTheNextOccurrence(): void
    {
        $february = new \DateTimeImmutable('2026-02-01');
        self::assertSame('2026-02-05', SubscriptionSchedule::next($february, 5, 11, 0)?->format('Y-m-d'));
        self::assertSame('2026-05-05', SubscriptionSchedule::next($february, 5, 11, 3)?->format('Y-m-d'));
        self::assertNull(SubscriptionSchedule::next($february, 5, 11, 11));
    }
}
