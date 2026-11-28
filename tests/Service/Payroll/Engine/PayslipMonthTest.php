<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipMonth;
use App\Service\Payroll\Engine\PayslipPresence;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Months the employee is not paid whole (lot 4, step 4.3d): hired or leaving during the month, absent
 * without pay, off sick with the salary partly kept. Every expected figure was calculated apart from the
 * engine by the decimal script reference/bulletins_4_3c.py (cases 7 to 12), from these rules:
 *
 * - the days outside the contract come off at the salary ÷ the days of the month (four decimals);
 * - the absences never take more than the salary;
 * - the ceiling of the month is the monthly ceiling × (calendar days of the employment period − whole
 *   days paid nothing at all) ÷ the days of the month, to the cent (R242-2 CSS, DSN sheet 2713);
 * - the SMIC of reference is corrected by the salary due ÷ the salary of the whole month, on the salary,
 *   the absences and the sick pay alone, rounded once (D241-7 IV CSS);
 * - the sick pay is the hours kept × (hourly rate × 90 %, four decimals), less the daily allowances.
 */
final class PayslipMonthTest extends TestCase
{
    use StandardPlanFixture;

    /** Élodie Masson, hired on 15 June 2026: 16 days of 30, 2 500,00, withholding rate 3,8 %. */
    public function testAnEmployeeHiredDuringTheMonth(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('2500.00', withholding: '3.8', hiredOn: '2026-06-15'), PayslipVariables::none());
        $totals = $payslip->totals;

        // 14 days outside × 2 500,00 ÷ 30 = 83,3333 → 1 166,66662 → 1 166,67 ; gross 1 333,33.
        $this->assertRow($payslip, '1230', '14', '83.3333', '-1166.67');
        self::assertSame('1333.33', $this->amount($payslip->gross));
        // Ceiling 4 005,00 × 16 ÷ 30 = 2 136,00 ; SMIC 1 823,03 × 1 333,33 ÷ 2 500,00 = 972,28 ; C 0,1719, 229,20.
        self::assertSame(['2136.00', '972.28', '0.1719', '229.20'], [$this->amount($payslip->yearToDate->ceiling), $this->amount($payslip->generalReduction?->smic), (string) $payslip->generalReduction?->coefficient, $this->amount($payslip->generalReduction?->amount)]);
        self::assertSame(['194.60', '34.60'], [$this->amount($payslip->generalReduction?->urssafShare), $this->amount($payslip->generalReduction?->retirementShare)]);
        self::assertSame(['308.58', '370.99', '1024.75', '1091.55', '41.48', '983.27', '1704.32'], $this->figures($payslip));
        self::assertStringContainsString('16 ÷ 30', $payslip->row('2200')->explanation->base ?? '');
    }

    /** Leaving on 20 May 2026 after four whole months: 20 days of 31, 2 600,00 + 5 %, withholding rate 6 %. */
    public function testAnEmployeeLeavingDuringTheMonthAfterFourMonths(): void
    {
        $payslip = $this->payslip('2026-05-31', $this->employee('2600.00', withholding: '6', leavesOn: '2026-05-20'), new PayslipVariables(rates: ['1010' => BigDecimal::of('5')]), new PayslipYearToDate(
            gross: Money::euros('10920.00'),
            ceiling: Money::euros('16020.00'),
            smic: Money::euros('7292.12'),
            generalReduction: Money::euros('1453.44'),
            netTaxable: Money::euros('8947.72'),
        ));

        // 11 days × 2 600,00 ÷ 31 = 83,8710 → 922,581 → 922,58 ; gross 2 600,00 − 922,58 + 130,00 = 1 807,42.
        $this->assertRow($payslip, '1230', '11', '83.871', '-922.58');
        self::assertSame('1807.42', $this->amount($payslip->gross));
        // Year: ceiling 16 020,00 + 2 583,87 (4 005,00 × 20 ÷ 31) ; SMIC 7 292,12 + 1 176,15 (1 823,03 × 1 677,42 ÷ 2 600,00) ;
        // reduction 0,1316 × 12 727,42 = 1 674,93, less 1 453,44 = 221,49.
        $year = $payslip->yearToDate;
        self::assertSame(['12727.42', '18603.87', '8468.27', '1674.93', '10428.07'], [$this->amount($year->gross), $this->amount($year->ceiling), $this->amount($year->smic), $this->amount($year->generalReduction), $this->amount($year->netTaxable)]);
        self::assertSame(['0.1316', '-221.49'], [(string) $payslip->generalReduction?->coefficient, $this->amount($payslip->row('2900')?->employerAmount)]);
        self::assertSame(['407.38', '582.19', '1400.04', '1480.35', '88.82', '1311.22', '2389.61'], $this->figures($payslip));
    }

    /** Damien Fournier, October 2026, off sick: 56 h absent, 21 h kept at 90 %, 95,76 of daily allowances, 7 days paid nothing. */
    public function testASickLeaveWithTheSalaryPartlyKept(): void
    {
        $payslip = $this->payslip('2026-10-31', $this->employee('1867.02', withholding: '0.5'), new PayslipVariables(
            quantities: ['1210' => BigDecimal::of('56'), '1220' => BigDecimal::of('21')],
            amounts: ['1220' => Money::euros('95.76')],
            rates: ['1010' => BigDecimal::of('5')],
            unpaidDays: 7,
        ));

        // Hourly 1 867,02 ÷ 151,67 = 12,3098 ; absence 56 × 12,3098 = 689,35.
        $this->assertRow($payslip, '1210', '56', '12.3098', '-689.35');
        // Kept 12,3098 × 90 % = 11,0788 ; 21 × 11,0788 = 232,65, less 95,76 = 136,89.
        $this->assertRow($payslip, '1220', '21', '11.0788', '136.89');
        self::assertSame('1407.91', $this->amount($payslip->gross));
        // Ceiling 4 005,00 × 24 ÷ 31 = 3 100,65 ; SMIC 1 823,03 × 1 314,56 ÷ 1 867,02 = 1 283,59 ; C 0,3149, 443,35.
        self::assertSame(['3100.65', '1283.59', '0.3149', '443.35'], [$this->amount($payslip->yearToDate->ceiling), $this->amount($payslip->generalReduction?->smic), (string) $payslip->generalReduction?->coefficient, $this->amount($payslip->generalReduction?->amount)]);
        self::assertSame(['324.14', '188.88', '1083.77', '1152.70', '5.76', '1078.01', '1596.79'], $this->figures($payslip));
        self::assertStringContainsString('95,76', $payslip->row('1220')->explanation->formula ?? '');
    }

    /** Sandrine Gauthier, part time (104 h), January 2026: a whole Monday of 8 h unpaid. */
    public function testAPartTimeEmployeeAbsentAWholeDayWithoutPay(): void
    {
        $payslip = $this->payslip('2026-01-31', $this->employee('1476.80', hours: '104', withholding: '1.3'), new PayslipVariables(
            quantities: ['1200' => BigDecimal::of('8')],
            rates: ['1010' => BigDecimal::of('7')],
            unpaidDays: 1,
        ));

        // 8 × 14,2000 = 113,60 ; gross 1 476,80 − 113,60 + 103,38 = 1 466,58.
        $this->assertRow($payslip, '1200', '8', '14.2', '-113.60');
        self::assertSame('1466.58', $this->amount($payslip->gross));
        // The part time keeps its whole ceiling but for the day: 4 005,00 × 30 ÷ 31 = 3 875,81 ;
        // SMIC 1 823,03 × 104 ÷ 151,67 × 1 363,20 ÷ 1 476,80 = 1 153,89.
        self::assertSame(['3875.81', '1153.89', '0.2126', '311.79'], [$this->amount($payslip->yearToDate->ceiling), $this->amount($payslip->generalReduction?->smic), (string) $payslip->generalReduction?->coefficient, $this->amount($payslip->generalReduction?->amount)]);
        self::assertSame(['336.35', '345.61', '1130.23', '1200.82', '15.61', '1114.62', '1812.19'], $this->figures($payslip));
    }

    /** A whole month unpaid (160 h, more than the salary's 151,67 h): the absence stops at the salary, nothing is due. */
    public function testAnAbsenceNeverTakesMoreThanTheSalary(): void
    {
        $payslip = PayslipCalculator::calculate(new PayslipInput(
            $this->employee('2000.00'),
            new PayslipVariables(quantities: ['1200' => BigDecimal::of('160')], unpaidDays: 30),
            self::regulatoryOn('2026-06-30'),
            self::standardPlan(),
            self::establishment(),
        ));

        // 160 × 13,1865 = 2 109,84, limited to 2 000,00.
        $this->assertRow($payslip, '1200', '160', '13.1865', '-2000.00');
        self::assertStringContainsString('limité à 2', $payslip->row('1200')->explanation->formula ?? '');
        self::assertSame('0.00', $this->amount($payslip->gross));
        self::assertNull($payslip->generalReduction);
        self::assertSame(['0.00', '0.00'], [$this->amount($payslip->yearToDate->ceiling), $this->amount($payslip->yearToDate->smic)]);
        self::assertSame(['0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00'], $this->figures($payslip));
    }

    /** Hired on 10 March 2025: 22 days of 31, 2 100,00, no rate sent - the reduced rates and the RGCP on the corrected SMIC. */
    public function testA2025EmployeeHiredDuringTheMonth(): void
    {
        $payslip = $this->payslip('2025-03-31', $this->employee('2100.00', hiredOn: '2025-03-10'), PayslipVariables::none());
        $totals = $payslip->totals;

        // 9 × 2 100,00 ÷ 31 = 67,7419 → 609,6771 → 609,68 ; gross 1 490,32.
        $this->assertRow($payslip, '1230', '9', '67.7419', '-609.68');
        // SMIC 1 801,80 × 1 490,32 ÷ 2 100,00 = 1 278,69: 1 490,32 ≤ 2,25 SMIC (7 %) and 3,3 SMIC (3,45 %).
        self::assertSame(['104.32', '51.42'], [$this->amount($payslip->row('2000')?->employerAmount), $this->amount($payslip->row('2400')?->employerAmount)]);
        // RGCP before May 2025: 0,3194 ÷ 0,6 × (1,6 × 1 278,69 ÷ 1 490,32 − 1) = 0,198451 → 0,1985 ; 295,83.
        // Ceiling 3 925,00 × 22 ÷ 31 = 2 785,48. Unemployment 4,05 % until April 2025.
        self::assertSame(['2785.48', '1278.69', '0.1985', '295.83'], [$this->amount($payslip->yearToDate->ceiling), $this->amount($payslip->generalReduction?->smic), (string) $payslip->generalReduction?->coefficient, $this->amount($payslip->generalReduction?->amount)]);
        // Neutral rate of the 2025 grid, not prorated for the incomplete month: 1 220,29 < 1 591 → 0 %.
        self::assertSame(['341.30', '254.92', '1149.02', '1220.29', '0.00', '1149.02', '1745.24'], $this->figures($payslip));
        self::assertTrue($totals->neutralRate);
        self::assertStringContainsString('sans proratisation', $totals->explanations['withholdingTax']->rate);
    }

    /** The calendar of a month: the employment period, the days outside, the ceiling's days. */
    public function testTheMonthOfAPayslip(): void
    {
        $june = new PayslipMonth(new \DateTimeImmutable('2026-06-30'), new \DateTimeImmutable('2026-06-15'));
        self::assertSame([30, 16, 14, 16], [$june->days, $june->presentDays, $june->outsideDays(), $june->ceilingDays()]);
        $february = new PayslipMonth(new \DateTimeImmutable('2026-02-27'), new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2026-02-10'), 3);
        self::assertSame([28, 10, 18, 7], [$february->days, $february->presentDays, $february->outsideDays(), $february->ceilingDays()]);
        // 4 005,00 × 7 ÷ 28 = 1 001,25 ; a whole month keeps the ceiling as it is.
        self::assertSame('1001.25', (string) $february->ceiling(BigDecimal::of('4005')));
        self::assertSame('4005', (string) (new PayslipMonth(new \DateTimeImmutable('2026-03-31')))->ceiling(BigDecimal::of('4005')));

        $this->expectException(\InvalidArgumentException::class);
        new PayslipMonth(new \DateTimeImmutable('2026-06-30'), new \DateTimeImmutable('2026-07-01'));
    }

    /** No rubrique of the standard plan stands on its code: the absences are read from their base. */
    public function testTheAbsencesAreReadFromTheirBase(): void
    {
        $codes = static fn (callable $test): array => array_values(array_map(static fn (PayrollItemSpec $spec): string => $spec->code, array_filter(self::standardPlan(), $test)));

        self::assertSame(['1200', '1210', '1230'], $codes(PayslipPresence::isAbsence(...)));
        self::assertSame(['1220'], $codes(PayslipPresence::isSickPay(...)));
        self::assertSame(['1000'], $codes(PayslipPresence::isSalary(...)));
    }

    private function employee(string $salary, string $hours = '151.67', ?string $withholding = null, ?string $hiredOn = null, ?string $leavesOn = null): PayslipEmployee
    {
        return new PayslipEmployee('Salarié', false, ContractType::Permanent, $hours, Money::euros($salary), null === $withholding ? null : BigDecimal::of($withholding), null === $hiredOn ? null : new \DateTimeImmutable($hiredOn), null === $leavesOn ? null : new \DateTimeImmutable($leavesOn));
    }

    private function payslip(string $day, PayslipEmployee $employee, PayslipVariables $variables, ?PayslipYearToDate $yearToDate = null): Payslip
    {
        return PayslipCalculator::calculate(new PayslipInput($employee, $variables, self::regulatoryOn($day), self::demoPlan(), self::establishment(), $yearToDate ?? PayslipYearToDate::none()));
    }

    /** @return list<string> employee and employer contributions, net social, net taxable, tax, net paid, employer's cost */
    private function figures(Payslip $payslip): array
    {
        $totals = $payslip->totals;

        return array_map($this->amount(...), [$totals->employeeContributions, $totals->employerContributions, $totals->netSocial, $totals->netTaxable, $totals->withholdingTax, $totals->netPay, $totals->employerCost]);
    }

    private function amount(?Money $money): string
    {
        return null === $money ? 'null' : (string) $money->toDecimal();
    }

    private function assertRow(Payslip $payslip, string $code, ?string $base, ?string $rate, string $amount): void
    {
        $row = $payslip->row($code);
        self::assertNotNull($row, 'No line '.$code);
        self::assertSame([$base, $rate, $amount], [
            null === $row->base ? null : (string) $row->base->strippedOfTrailingZeros(),
            null === $row->employeeRate ? null : (string) $row->employeeRate->strippedOfTrailingZeros(),
            (string) $row->employeeAmount->toDecimal(),
        ], 'Line '.$code);
    }
}
