<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\Money;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testEurosAreReadExactlyIntoCents(): void
    {
        self::assertSame(123456, Money::euros('1234.56')->cents);
        self::assertSame(-13800, Money::euros('-138')->cents);
        self::assertSame(5, Money::euros('0.05')->cents);
        self::assertSame(200, Money::euros(2)->cents);
    }

    public function testAThirdDecimalIsNeverRoundedWithoutARule(): void
    {
        $this->expectException(RoundingNecessaryException::class);
        Money::euros('0.125');
    }

    public function testTheRoundingIsTheCallersRule(): void
    {
        self::assertSame(13, Money::euros('0.125', RoundingMode::HalfUp)->cents);
        self::assertSame(12, Money::euros('0.125', RoundingMode::HalfEven)->cents);
        self::assertSame(12, Money::euros('0.129', RoundingMode::Down)->cents);
    }

    public function testArithmeticStaysInCents(): void
    {
        $a = Money::cents(276000);
        $b = Money::cents(13800);

        self::assertSame(262200, $a->minus($b)->cents);
        self::assertSame(289800, $a->plus($b)->cents);
        self::assertSame(-276000, $a->negated()->cents);
        self::assertSame(276000, $a->negated()->abs()->cents);
        self::assertSame(75, Money::sum([Money::cents(100), Money::cents(-30), Money::cents(5)])->cents);
        self::assertTrue(Money::sum([])->isZero());
    }

    public function testTimesARateNamesItsRounding(): void
    {
        // 2 760,00 × 20 % = 552,00 ; 19,99 × 20 % = 3,998 → 4,00 ; 10,05 × 5,5 % = 0,55275 → 0,55.
        self::assertSame(55200, Money::cents(276000)->times('0.20', RoundingMode::HalfUp)->cents);
        self::assertSame(400, Money::cents(1999)->times('0.20', RoundingMode::HalfUp)->cents);
        self::assertSame(399, Money::cents(1999)->times('0.20', RoundingMode::Down)->cents);
        self::assertSame(55, Money::cents(1005)->times('0.055', RoundingMode::HalfUp)->cents);
        // 151,67 h × 11,88 € = 1 801,8396 → 1 801,84.
        self::assertSame(180184, Money::cents(1188)->times('151.67', RoundingMode::HalfUp)->cents);
    }

    public function testComparisons(): void
    {
        self::assertTrue(Money::cents(5)->equals(Money::cents(5)));
        self::assertSame(-1, Money::cents(4)->compareTo(Money::cents(5)));
        self::assertSame(1, Money::cents(6)->compareTo(Money::cents(5)));
        self::assertTrue(Money::cents(-1)->isNegative());
        self::assertTrue(Money::cents(1)->isPositive());
        self::assertTrue(Money::zero()->isZero());
        self::assertSame('1234.56', (string) Money::cents(123456)->toDecimal());
    }

    public function testAnOverflowThrowsInsteadOfBecomingAFloat(): void
    {
        $this->expectException(IntegerOverflowException::class);
        Money::cents(\PHP_INT_MAX)->plus(Money::cents(1));
    }

    public function testJsonCarriesIntegerCents(): void
    {
        self::assertSame('{"total":123456}', json_encode(['total' => Money::cents(123456)]));
    }
}
