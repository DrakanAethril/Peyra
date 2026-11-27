<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Service\Regulatory\RegulatoryHistory;
use App\Tests\Support\IdentityFixtures;
use App\Tests\Support\RegulatoryFixtures;
use PHPUnit\Framework\TestCase;

final class RegulatoryHistoryTest extends TestCase
{
    public function testEachDateAppliesUntilTheEveOfTheNext(): void
    {
        $history = RegulatoryHistory::of([
            RegulatoryFixtures::fromFile('social.smic_hourly', '2024-11-01', '11.88', 2025),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-01-01', '12.02'),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-06-01', '12.31'),
        ]);

        self::assertCount(3, $history->versions);
        self::assertSame('2025-12-31', $history->versions[0]->until?->format('Y-m-d'));
        self::assertSame('2026-05-31', $history->versions[1]->until?->format('Y-m-d'));
        self::assertNull($history->versions[2]->until);
        self::assertSame('12.31', $history->latest()->getTree());

        self::assertNull($history->inForceOn(new \DateTimeImmutable('2024-10-31')));
        self::assertSame('11.88', $history->inForceOn(new \DateTimeImmutable('2025-12-31'))?->getTree());
        self::assertSame('12.02', $history->inForceOn(new \DateTimeImmutable('2026-01-01'))?->getTree());
        self::assertSame('12.02', $history->inForceOn(new \DateTimeImmutable('2026-05-31'))?->getTree());
        self::assertSame('12.31', $history->inForceOn(new \DateTimeImmutable('2026-06-01'))?->getTree());
    }

    public function testTheLastRowOfADateCorrectsTheEarlierOnes(): void
    {
        $martin = IdentityFixtures::teacher(1, 'Jean-Pierre Martin');
        $file = RegulatoryFixtures::fromFile('social.pss_monthly', '2026-01-01', '4000');
        $corrected = RegulatoryFixtures::byAdministrator($file, '2026-01-01', '4005', $martin);
        $history = RegulatoryHistory::of([$file, $corrected]);

        self::assertCount(1, $history->versions);
        self::assertSame($corrected, $history->versions[0]->value);
        self::assertSame([$file], $history->versions[0]->corrected);
        self::assertSame(1, $history->correctionCount());
        self::assertSame('4005', $history->inForceOn(new \DateTimeImmutable('2026-03-15'))?->getTree());
        self::assertSame($history->versions[0], $history->versionOf(new \DateTimeImmutable('2026-01-01')));
        self::assertNull($history->versionOf(new \DateTimeImmutable('2026-01-02')));
    }

    public function testDuringAYearListsEveryVersionApplyingThatYear(): void
    {
        $history = RegulatoryHistory::of([
            RegulatoryFixtures::fromFile('fiscal.late_payment.recovery_indemnity', '2013-01-01', '40', 2025),
            RegulatoryFixtures::fromFile('social.x', '2024-11-01', '1', 2025),
            RegulatoryFixtures::fromFile('social.x', '2026-01-01', '2'),
            RegulatoryFixtures::fromFile('social.x', '2026-06-01', '3'),
        ]);

        self::assertSame(['1'], array_map(static fn ($v) => $v->value->getTree(), $history->during(2025)));
        self::assertSame(['2', '3'], array_map(static fn ($v) => $v->value->getTree(), $history->during(2026)));
        self::assertSame(['3'], array_map(static fn ($v) => $v->value->getTree(), $history->during(2030)));
        self::assertSame([], $history->during(2012));
    }

    public function testByKeyGroupsTheRows(): void
    {
        $histories = RegulatoryHistory::byKey([
            RegulatoryFixtures::fromFile('fiscal.a', '2026-01-01', '1'),
            RegulatoryFixtures::fromFile('social.b', '2026-01-01', '2'),
            RegulatoryFixtures::fromFile('social.b', '2026-06-01', '3'),
        ]);

        self::assertSame(['fiscal.a', 'social.b'], array_keys($histories));
        self::assertCount(2, $histories['social.b']->versions);
    }
}
