<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Hiring;

use App\Service\Payroll\Hiring\DpaeControls;
use App\Service\Shared\Portal\PortalFault;
use PHPUnit\Framework\TestCase;

/** What the simulated social portal refuses in a DPAE (step 4.2b): the missing mentions, a deposit more than 8 days ahead. */
final class DpaeControlsTest extends TestCase
{
    public function testACompleteDpaeWithinItsWindowIsAccepted(): void
    {
        // Hired 01/04/2026: open from 24/03, accepted up to and after the hire (late then).
        foreach (['2026-03-24', '2026-03-30', '2026-04-01', '2026-04-03'] as $on) {
            self::assertSame([], $this->codes('16.23Z', 'Feurs (42)', '07:30', $on), $on);
        }
        self::assertFalse(DpaeControls::isLate(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-01')));
        self::assertTrue(DpaeControls::isLate(new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02')));
    }

    public function testEachMissingMentionAndTooEarlyADepositIsAMotive(): void
    {
        self::assertSame(['DPAE-E04'], $this->codes('16.23Z', 'Feurs (42)', '07:30', '2026-03-23'), '23/03 is 9 days before the hire.');
        self::assertSame(['DPAE-E01', 'DPAE-E02', 'DPAE-E03', 'DPAE-E04'], $this->codes(null, ' ', null, '2026-03-01'));

        $faults = DpaeControls::check(null, 'Feurs (42)', '07:30', new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-03-20'), 8);
        self::assertSame('Dépôt trop tôt : embauche le 01/04/2026, dépôt possible à partir du 24/03/2026.', $faults[1]->motive);
        self::assertStringContainsString('8 jours avant l’embauche', $faults[1]->explanation);
    }

    /** @return list<string> */
    private function codes(?string $naf, ?string $birthPlace, ?string $hireTime, string $on): array
    {
        return array_map(static fn (PortalFault $fault): string => $fault->code, DpaeControls::check($naf, $birthPlace, $hireTime, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable($on), 8));
    }
}
