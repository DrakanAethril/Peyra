<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Declaration;

use App\Enum\Payroll\SocialDueState;
use App\Service\Payroll\Declaration\SocialDueDate;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The DSN's due date and a payment's state at the dossier's date (step 4.6), pure: the 5th of the next
 * month from 50 employees, the 15th below (service-public F34059, `social.dsn.due_day`).
 */
final class SocialDueDateTest extends TestCase
{
    public function testTheDueDateByHeadcount(): void
    {
        $due = new SocialDueDate(BigDecimal::of('50'), 5, 15);

        self::assertSame('2026-04-15', $due->of(new \DateTimeImmutable('2026-03-01'), BigDecimal::of('8.69'))->format('Y-m-d'), 'Menuiserie Vasseur, under 50.');
        self::assertSame('2026-04-15', $due->of(new \DateTimeImmutable('2026-03-31'), BigDecimal::of('49.99'))->format('Y-m-d'), 'Any day of the month.');
        self::assertSame('2026-04-05', $due->of(new \DateTimeImmutable('2026-03-01'), BigDecimal::of('50'))->format('Y-m-d'), 'From the threshold.');
        self::assertSame('2027-01-15', $due->of(new \DateTimeImmutable('2026-12-01'), BigDecimal::zero())->format('Y-m-d'), 'December’s, next January.');
        self::assertSame('2026-02-15', $due->of(new \DateTimeImmutable('2026-01-31'), BigDecimal::of('3'))->format('Y-m-d'), 'January’s 31st does not overflow February.');
    }

    public function testTheStateAtTheDossiersDate(): void
    {
        $due = new \DateTimeImmutable('2026-04-15');

        self::assertSame(SocialDueState::Due, SocialDueState::at(false, $due, new \DateTimeImmutable('2026-04-15')), 'Due on its day.');
        self::assertSame(SocialDueState::Late, SocialDueState::at(false, $due, new \DateTimeImmutable('2026-04-16')));
        self::assertSame(SocialDueState::Paid, SocialDueState::at(true, $due, new \DateTimeImmutable('2026-05-31')), 'Paid late is paid.');
    }
}
