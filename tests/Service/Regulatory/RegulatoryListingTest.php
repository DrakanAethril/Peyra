<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Enum\Regulatory\RegulatoryDomain;
use App\Service\Regulatory\RegulatoryHistory;
use App\Service\Regulatory\RegulatoryListing;
use App\Tests\Support\RegulatoryFixtures;
use PHPUnit\Framework\TestCase;

final class RegulatoryListingTest extends TestCase
{
    /** @return array<string, RegulatoryHistory> */
    private function histories(): array
    {
        return RegulatoryHistory::byKey([
            RegulatoryFixtures::fromFile('fiscal.late_payment.recovery_indemnity', '2013-01-01', '40', 2025, label: 'Indemnité forfaitaire de recouvrement'),
            RegulatoryFixtures::fromFile('fiscal.vat.rates', '2026-01-01', ['normal' => '20'], verified: false, label: 'Taux de TVA'),
            RegulatoryFixtures::fromFile('fiscal.vat.simplified_regime_abolition', '2027-01-01', 'Fin du RSI', unit: 'text', label: 'Fin du régime simplifié'),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2024-11-01', '11.88', 2025, label: 'SMIC horaire brut'),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-01-01', '12.02', label: 'SMIC horaire brut'),
            RegulatoryFixtures::fromFile('social.smic_hourly', '2026-06-01', '12.31', label: 'SMIC horaire brut'),
        ]);
    }

    public function testAMillesimeListsWhatAppliesThatYear(): void
    {
        $listing = RegulatoryListing::of($this->histories(), 2026, null, '');

        self::assertSame(
            ['fiscal.late_payment.recovery_indemnity@2013-01-01', 'fiscal.vat.rates@2026-01-01', 'social.smic_hourly@2026-01-01', 'social.smic_hourly@2026-06-01'],
            array_map(static fn ($v) => $v->value->getKey().'@'.$v->value->getEffectiveFrom()->format('Y-m-d'), $listing->rows),
        );
        self::assertSame(4, $listing->total);
        self::assertSame(1, $listing->unverified);
        self::assertSame(['social.smic_hourly@2024-11-01'], array_map(static fn ($v) => $v->value->getKey().'@'.$v->value->getEffectiveFrom()->format('Y-m-d'), RegulatoryListing::of($this->histories(), 2025, RegulatoryDomain::Social, '')->rows));
    }

    public function testDomainAndSearchNarrowIt(): void
    {
        self::assertSame(2, RegulatoryListing::of($this->histories(), 2026, RegulatoryDomain::Social, '')->total);
        self::assertSame(2, RegulatoryListing::of($this->histories(), 2026, null, 'smic')->total, 'by label, any case');
        self::assertSame(1, RegulatoryListing::of($this->histories(), 2026, null, 'vat.rates')->total, 'by key');
        self::assertSame(['' => 2, 'social' => 2, 'fiscal' => 0], RegulatoryListing::counts($this->histories(), 2026, 'SMIC'));
    }

    public function testTheMillesimesRunFromTheOldestFileToTheLastYearAValueTakesEffect(): void
    {
        self::assertSame([2027, 2026, 2025], RegulatoryListing::years($this->histories()));
        self::assertSame([], RegulatoryListing::years([]));
    }
}
