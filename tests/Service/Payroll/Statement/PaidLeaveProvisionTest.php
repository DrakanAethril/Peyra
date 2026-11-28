<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Statement;

use App\Service\Payroll\Statement\PaidLeaveProvision;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The provision for paid leave (step 4.5), pure: the tenth of the gross pay of the reference period, and
 * the employer's contributions at the employee's average rate - each figure computed by hand below.
 */
final class PaidLeaveProvisionTest extends TestCase
{
    public function testTheTenthAndTheChargesAtTheAverageRate(): void
    {
        $provision = PaidLeaveProvision::at([
            // 30 000,00 × 10 % = 3 000,00; 9 000 ÷ 30 000 = 30,00 %; 3 000 × 30 % = 900,00.
            ['number' => 'S001', 'name' => 'Martin Paul', 'gross' => Money::euros('30000'), 'employer' => Money::euros('9000'), 'months' => 10],
            // 12 345,67 × 10 % = 1 234,567 → 1 234,57; 3 210,98 × 100 ÷ 12 345,67 = 26,0093… → 26,01 %; 1 234,57 × 26,01 % = 321,1116… → 321,11.
            ['number' => 'S002', 'name' => 'Bernard Anne', 'gross' => Money::euros('12345.67'), 'employer' => Money::euros('3210.98'), 'months' => 4],
            // Hired after the day's payslips: nothing acquired, no line.
            ['number' => 'S003', 'name' => 'Petit Léa', 'gross' => Money::zero(), 'employer' => Money::zero(), 'months' => 0],
        ], BigDecimal::of('10'));

        self::assertSame([['S001', 300000, '30.00', 90000], ['S002', 123457, '26.01', 32111]], array_map(static fn (array $line): array => [$line['number'], $line['provision']->cents, (string) $line['rate'], $line['charges']->cents], $provision['lines']));
        self::assertSame([423457, 122111], [$provision['provision']->cents, $provision['charges']->cents], '3 000 + 1 234,57; 900 + 321,11.');
        self::assertStringContainsString('10 % × 12 345,67 € = 1 234,57 €', str_replace(["\u{202F}", "\u{A0}"], ' ', $provision['lines'][1]['explanation']));
    }

    public function testTheReferencePeriodBeginsOnTheFirstOfJune(): void
    {
        self::assertSame('2025-06-01', PaidLeaveProvision::periodStart(new \DateTimeImmutable('2026-03-31'), 6, 1)->format('Y-m-d'));
        self::assertSame('2026-06-01', PaidLeaveProvision::periodStart(new \DateTimeImmutable('2026-06-01'), 6, 1)->format('Y-m-d'));
        self::assertSame('2026-06-01', PaidLeaveProvision::periodStart(new \DateTimeImmutable('2026-12-31'), 6, 1)->format('Y-m-d'));
    }
}
