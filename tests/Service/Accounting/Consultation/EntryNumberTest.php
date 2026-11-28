<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Consultation;

use App\Service\Accounting\Consultation\EntryNumber;
use PHPUnit\Framework\TestCase;

final class EntryNumberTest extends TestCase
{
    public function testANumberAsShown(): void
    {
        $number = EntryNumber::parse('ACH-2026-00012');

        self::assertNotNull($number);
        self::assertSame(['ACH', '2026', 12], [$number->journal, $number->exercise, $number->number]);
    }

    public function testWithoutCaseNorLeadingZeros(): void
    {
        $number = EntryNumber::parse(' bq2-2026-7 ');

        self::assertNotNull($number);
        self::assertSame(['BQ2', '2026', 7], [$number->journal, $number->exercise, $number->number]);
    }

    public function testWhatIsNotANumber(): void
    {
        foreach (['FA-2601', 'ACH-2026', 'ACH-2026-0', 'ACH 2026 12', 'ACHATS7-2026-1', '4158,12', ''] as $typed) {
            self::assertNull(EntryNumber::parse($typed), $typed);
        }
    }
}
