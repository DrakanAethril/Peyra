<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier;

use App\Service\Dossier\PeriodPlan;
use PHPUnit\Framework\TestCase;

/** An exercise cut into calendar months, the first and the last cut by its bounds; 1 to 24 months. */
final class PeriodPlanTest extends TestCase
{
    public function testACalendarYearMakesTwelveMonths(): void
    {
        $plan = self::days(PeriodPlan::monthly(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));

        self::assertCount(12, $plan);
        self::assertSame(['2026-01-01', '2026-01-31'], $plan[0]);
        self::assertSame(['2026-02-01', '2026-02-28'], $plan[1]);
        self::assertSame(['2026-12-01', '2026-12-31'], $plan[11]);
    }

    public function testAFirstExerciseStartingMidMonthAndRunningEighteenMonths(): void
    {
        // Created on 15/03/2027, first closing on 31/08/2028: 18 periods, the first one cut.
        $plan = self::days(PeriodPlan::monthly(new \DateTimeImmutable('2027-03-15'), new \DateTimeImmutable('2028-08-31')));

        self::assertCount(18, $plan);
        self::assertSame(['2027-03-15', '2027-03-31'], $plan[0]);
        self::assertSame(['2028-02-01', '2028-02-29'], $plan[11]);
        self::assertSame(['2028-08-01', '2028-08-31'], $plan[17]);
    }

    public function testAnExerciseEndingMidMonthCutsItsLastPeriod(): void
    {
        $plan = self::days(PeriodPlan::monthly(new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-15')));

        self::assertCount(12, $plan);
        self::assertSame(['2027-06-01', '2027-06-15'], $plan[11]);
    }

    public function testOneDayIsOnePeriod(): void
    {
        self::assertSame([['2026-05-31', '2026-05-31']], self::days(PeriodPlan::monthly(new \DateTimeImmutable('2026-05-31'), new \DateTimeImmutable('2026-05-31'))));
    }

    public function testAnExerciseEndsAfterItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PeriodPlan::monthly(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2026-01-01'));
    }

    public function testTwentyFourMonthsAtMost(): void
    {
        self::assertCount(24, PeriodPlan::monthly(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-12-31')));

        $this->expectException(\InvalidArgumentException::class);
        PeriodPlan::monthly(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2028-01-01'));
    }

    /**
     * @param list<array{\DateTimeImmutable, \DateTimeImmutable}> $plan
     *
     * @return list<array{string, string}>
     */
    private static function days(array $plan): array
    {
        return array_map(static fn (array $bounds): array => [$bounds[0]->format('Y-m-d'), $bounds[1]->format('Y-m-d')], $plan);
    }
}
