<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Service\Payroll\Engine\DecimalPower;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

final class DecimalPowerTest extends TestCase
{
    public function testExactPowersAndRoots(): void
    {
        // 16^0,75 = (16^3)^(1/4) = 4 096^(1/4) = 8.
        self::assertSame('8.000000', (string) DecimalPower::of(BigDecimal::of('16'), BigDecimal::of('0.75'), 6));
        self::assertSame('2.500000', (string) DecimalPower::of(BigDecimal::of('2.5'), BigDecimal::one(), 6));
        self::assertSame('0.000000', (string) DecimalPower::of(BigDecimal::zero(), BigDecimal::of('1.75'), 6));
        // √2 = 1,41421356237…
        self::assertSame('1.41421356', (string) DecimalPower::of(BigDecimal::of('2'), BigDecimal::of('0.5'), 8));
    }

    public function testTheRgduBracketOfMaquette4(): void
    {
        // 0,606208^1,75 = exp(1,75 × ln 0,606208) = exp(1,75 × −0,500532) = exp(−0,875931) = 0,416474 (to six places).
        self::assertSame('0.416474', (string) DecimalPower::of(BigDecimal::of('0.606208'), BigDecimal::of('1.75'), 10)->toScale(6, RoundingMode::HalfUp));
    }

    public function testANegativeBaseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DecimalPower::of(BigDecimal::of('-1'), BigDecimal::of('1.75'), 4);
    }
}
