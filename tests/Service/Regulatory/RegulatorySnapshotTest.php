<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Service\Regulatory\MissingRegulatoryValue;
use App\Service\Regulatory\RegulatorySnapshot;
use App\Tests\Support\RegulatoryFixtures;
use PHPUnit\Framework\TestCase;

final class RegulatorySnapshotTest extends TestCase
{
    private function snapshot(): RegulatorySnapshot
    {
        $values = [
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-06-01', '12.31', unit: 'EUR/heure'),
            RegulatoryFixtures::fromFile('social.old_age_capped_employee', '2026-01-01', '6.9', unit: 'percent'),
            RegulatoryFixtures::fromFile('social.rgdu', '2026-01-01', ['tmin' => '0.02', 'tdelta' => ['under_50' => '0.3781', 'over_50' => '0.3821'], 'p' => '1.75'], unit: 'coefficient'),
            RegulatoryFixtures::fromFile('social.pss_annual', '2026-01-01', '48060', unit: 'EUR/an'),
            RegulatoryFixtures::fromFile('fiscal.vat.normal_regime', '2026-01-01', ['declaration' => '3310-CA3 mensuelle'], unit: 'text'),
        ];
        $byKey = [];
        foreach ($values as $value) {
            $byKey[$value->getKey()] = $value;
        }

        return new RegulatorySnapshot(new \DateTimeImmutable('2026-07-15'), $byKey);
    }

    public function testTypedReadings(): void
    {
        $snapshot = $this->snapshot();

        self::assertSame('12.31', $snapshot->decimal('social.smic_hourly')->toString());
        self::assertSame(1231, $snapshot->money('social.smic_hourly')->cents);
        self::assertSame('0.069', $snapshot->rate('social.old_age_capped_employee')->toString(), '6,90 % as a fraction');
        self::assertSame('0.3821', $snapshot->decimal('social.rgdu', 'tdelta', 'over_50')->toString());
        self::assertSame(48060, $snapshot->integer('social.pss_annual'));
        self::assertSame('3310-CA3 mensuelle', $snapshot->text('fiscal.vat.normal_regime', 'declaration'));
        self::assertTrue($snapshot->has('social.rgdu'));
        self::assertFalse($snapshot->has('social.absent'));
    }

    /** @return iterable<string, array{callable(RegulatorySnapshot): mixed}> */
    public static function refusals(): iterable
    {
        yield 'absent' => [static fn (RegulatorySnapshot $s) => $s->decimal('social.absent')];
        yield 'a structure, not a leaf' => [static fn (RegulatorySnapshot $s) => $s->decimal('social.rgdu', 'tdelta')];
        yield 'nothing at the path' => [static fn (RegulatorySnapshot $s) => $s->decimal('social.rgdu', 'nope')];
        yield 'a text, not a number' => [static fn (RegulatorySnapshot $s) => $s->decimal('fiscal.vat.normal_regime', 'declaration')];
        yield 'a number, not a text' => [static fn (RegulatorySnapshot $s) => $s->text('social.smic_hourly')];
        yield 'not in percent' => [static fn (RegulatorySnapshot $s) => $s->rate('social.smic_hourly')];
        yield 'more decimals than cents' => [static fn (RegulatorySnapshot $s) => $s->money('social.rgdu', 'tdelta', 'over_50')];
        yield 'not a whole number' => [static fn (RegulatorySnapshot $s) => $s->integer('social.smic_hourly')];
    }

    /** @param callable(RegulatorySnapshot): mixed $reading */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testNoDefaultIsEverMadeUp(callable $reading): void
    {
        $this->expectException(MissingRegulatoryValue::class);
        $reading($this->snapshot());
    }
}
