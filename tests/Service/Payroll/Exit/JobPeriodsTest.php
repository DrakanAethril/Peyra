<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Exit;

use App\Service\Payroll\Exit\JobPeriods;
use PHPUnit\Framework\TestCase;

/** The jobs of a work certificate (step 4.2c): a raise is not a new job, a promotion is. */
final class JobPeriodsTest extends TestCase
{
    public function testConsecutiveTermsOfOneJobMergeAndTheLastPeriodEndsOnTheLastDay(): void
    {
        $periods = JobPeriods::of([
            ['from' => new \DateTimeImmutable('2021-09-01'), 'job' => 'Assistante administrative'],
            ['from' => new \DateTimeImmutable('2023-01-01'), 'job' => 'Assistante administrative'],
            ['from' => new \DateTimeImmutable('2024-07-01'), 'job' => 'Comptable'],
            ['from' => new \DateTimeImmutable('2026-01-01'), 'job' => 'Comptable'],
            ['from' => new \DateTimeImmutable('2026-07-01'), 'job' => 'Responsable comptable'],
        ], new \DateTimeImmutable('2026-05-31'));

        self::assertSame([
            ['Assistante administrative', '2021-09-01', '2024-06-30'],
            ['Comptable', '2024-07-01', '2026-05-31'],
        ], array_map(static fn (array $p): array => [$p['job'], $p['from']->format('Y-m-d'), $p['to']->format('Y-m-d')], $periods));
    }
}
