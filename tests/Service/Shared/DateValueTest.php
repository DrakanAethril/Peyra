<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\DateValue;
use PHPUnit\Framework\TestCase;

final class DateValueTest extends TestCase
{
    public function testReadsARealDayAtMidnight(): void
    {
        self::assertSame('2026-11-12 00:00:00', DateValue::parse('2026-11-12')?->format('Y-m-d H:i:s'));
        self::assertSame('2028-02-29', DateValue::parse('2028-02-29')?->format('Y-m-d'));
        self::assertSame('2026-11-12', DateValue::parseFrench(' 12/11/2026 ')?->format('Y-m-d'));
    }

    public function testRefusesWhatIsNotADay(): void
    {
        self::assertNull(DateValue::parse('2026-02-31'));
        self::assertNull(DateValue::parse('2027-02-29'));
        self::assertNull(DateValue::parse('2026-11-12T10:00'));
        self::assertNull(DateValue::parse('12/11/2026'));
        self::assertNull(DateValue::parse(''));
        self::assertNull(DateValue::parseFrench('31/02/2026'));
        self::assertNull(DateValue::parseFrench('1/2/2026'));
        self::assertNull(DateValue::parseFrench('2026-11-12'));
    }
}
