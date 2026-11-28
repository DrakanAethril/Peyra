<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Engine\PaidLeave;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The gross pay of reference payslips (lot 4, step 4.3a), on the standard plan de paie and the
 * regulatory values of the repository. Every expected figure is worked out by hand in the comments,
 * from the employee's terms and the rates of plan/donnees/reglementaire/ - never copied from the
 * engine. The hourly rate is the contract's at four decimals, half up (15,8238 on maquette 4); a
 * line's amount is its printed base times its printed rate, rounded half up to the cent.
 */
final class PayslipGrossTest extends TestCase
{
    use StandardPlanFixture;

    /** Maquette 4: Thomas Lefèvre, November 2026, 2 400,00 for 151,67 h, seniority bonus 3 %. */
    public function testThomasLefevreOfMaquette4(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('Thomas Lefèvre', '2400.00'), new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]));

        // 2 400,00 ÷ 151,67 = 15,823828… → 15,8238; the salary is the contract's, mensualised.
        $this->assertRow($payslip, '1000', '151.67', PayslipUnit::Hours, '15.8238', PayslipUnit::EurosPerHour, '2400.00');
        // 2 400,00 × 3 % = 72,00.
        $this->assertRow($payslip, '1010', '2400.00', PayslipUnit::Euros, '3', PayslipUnit::Percent, '72.00');
        self::assertSame(['1000', '1010'], $this->codes($payslip));
        self::assertSame('2472.00', (string) $payslip->gross->toDecimal());
    }

    /** Mehdi Benali, June 2026: 2 150,00, bonus 7 %, 12 overtime hours at 25 %. */
    public function testOvertimeAt25Percent2026(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('Mehdi Benali', '2150.00'), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('12')],
            rates: ['1010' => BigDecimal::of('7')],
        ));

        // 2 150,00 × 7 % = 150,50.
        $this->assertRow($payslip, '1010', '2150.00', PayslipUnit::Euros, '7', PayslipUnit::Percent, '150.50');
        // 2 150,00 ÷ 151,67 = 14,175513… → 14,1755 ; × 1,25 = 17,719375 → 17,7194 ; × 12 h = 212,6328 → 212,63.
        $this->assertRow($payslip, '1100', '12', PayslipUnit::Hours, '17.7194', PayslipUnit::EurosPerHour, '212.63');
        // 2 150,00 + 150,50 + 212,63 = 2 513,13.
        self::assertSame('2513.13', (string) $payslip->gross->toDecimal());
        $explanation = $payslip->row('1100')?->explanation;
        self::assertNotNull($explanation?->source);
        self::assertSame('social.overtime.premium', $explanation->source->key);
        self::assertTrue($explanation->source->verified);
    }

    /** Sandrine Gauthier, December 2026: part time 104 h, 1 476,80, bonus 8 %, 10 complementary hours at 10 %. */
    public function testComplementaryHoursOfAPartTime(): void
    {
        $payslip = $this->payslip('2026-12-31', $this->employee('Sandrine Gauthier', '1476.80', '104'), new PayslipVariables(
            quantities: ['1120' => BigDecimal::of('10')],
            rates: ['1010' => BigDecimal::of('8')],
        ));

        // 1 476,80 ÷ 104 = 14,2000.
        $this->assertRow($payslip, '1000', '104', PayslipUnit::Hours, '14.2000', PayslipUnit::EurosPerHour, '1476.80');
        // 1 476,80 × 8 % = 118,144 → 118,14.
        $this->assertRow($payslip, '1010', '1476.80', PayslipUnit::Euros, '8', PayslipUnit::Percent, '118.14');
        // 14,2000 × 1,10 = 15,6200 ; × 10 h = 156,20.
        $this->assertRow($payslip, '1120', '10', PayslipUnit::Hours, '15.6200', PayslipUnit::EurosPerHour, '156.20');
        // 1 476,80 + 118,14 + 156,20 = 1 751,14.
        self::assertSame('1751.14', (string) $payslip->gross->toDecimal());
    }

    /** Kevin Brun, February 2026: SMIC of January 2026, 1 823,03, 7 h of unpaid absence, 10 meals provided. */
    public function testUnpaidAbsenceAndMealsAtTheSmic2026(): void
    {
        $payslip = $this->payslip('2026-02-28', $this->employee('Kevin Brun', '1823.03'), new PayslipVariables(
            quantities: ['1200' => BigDecimal::of('7'), '1410' => BigDecimal::of('10')],
        ));

        // 1 823,03 ÷ 151,67 = 12,019713… → 12,0197: the mensualised salary stays 1 823,03.
        $this->assertRow($payslip, '1000', '151.67', PayslipUnit::Hours, '12.0197', PayslipUnit::EurosPerHour, '1823.03');
        // 7 h × 12,0197 = 84,1379 → 84,14, taken off.
        $this->assertRow($payslip, '1200', '7', PayslipUnit::Hours, '12.0197', PayslipUnit::EurosPerHour, '-84.14');
        // Benefit in kind, food, 2026: 5,50 € a meal (social.benefit_in_kind.meal) × 10 = 55,00.
        $this->assertRow($payslip, '1410', '10', PayslipUnit::Count, '5.5', PayslipUnit::EurosPerUnit, '55.00');
        // 1 823,03 − 84,14 + 55,00 = 1 793,89.
        self::assertSame('1793.89', (string) $payslip->gross->toDecimal());
    }

    /** Paul Vasseur, January 2026: executive, 4 650,00, company car 322,50 a month. */
    public function testBenefitInKindEnteredAsAnAmount(): void
    {
        $payslip = $this->payslip('2026-01-31', $this->employee('Paul Vasseur', '4650.00', executive: true), new PayslipVariables(
            amounts: ['1400' => Money::euros('322.50')],
        ));

        $this->assertRow($payslip, '1400', null, null, null, null, '322.50');
        // 4 650,00 + 322,50 = 4 972,50.
        self::assertSame('4972.50', (string) $payslip->gross->toDecimal());
    }

    /** Claire Bonnet, August 2026: three weeks (18 working days, 105 h), the tenth is the better. */
    public function testPaidLeaveAtTheTenth(): void
    {
        $payslip = $this->payslip('2026-08-31', $this->employee('Claire Bonnet', '2350.00'), new PayslipVariables(
            rates: ['1010' => BigDecimal::of('10')],
            paidLeave: new PaidLeave('105', '18', '30', Money::euros('31020.00')),
        ));

        // 2 350,00 ÷ 151,67 = 15,494165… → 15,4942 ; 105 h × 15,4942 = 1 626,891 → 1 626,89 off the salary.
        $this->assertRow($payslip, '1300', '105', PayslipUnit::Hours, '15.4942', PayslipUnit::EurosPerHour, '-1626.89');
        // Kept salary 1 626,89. Tenth: 31 020,00 × 18 ÷ 30 = 18 612,00 ; × 10 % = 1 861,20 - the better.
        $this->assertRow($payslip, '1310', '18612.00', PayslipUnit::Euros, '10', PayslipUnit::Percent, '1861.20');
        // 2 350,00 + 235,00 − 1 626,89 + 1 861,20 = 2 819,31.
        self::assertSame('2819.31', (string) $payslip->gross->toDecimal());
        $explanation = $payslip->row('1310')?->explanation;
        self::assertNotNull($explanation);
        self::assertSame('social.paid_leave.pay', $explanation->source?->key);
        self::assertStringContainsString("1\u{202f}626,89", implode(' ', $explanation->notes));
    }

    /** Lucie Perrin, August 2025: 12 working days (70 h), a reference pay too low for the tenth: the salary is kept. */
    public function testPaidLeaveKeepingTheSalary2025(): void
    {
        $payslip = $this->payslip('2025-08-31', $this->employee('Lucie Perrin', '2600.00'), new PayslipVariables(
            rates: ['1010' => BigDecimal::of('4')],
            paidLeave: new PaidLeave('70', '12', '30', Money::euros('29000.00')),
        ));

        // 2 600,00 ÷ 151,67 = 17,142480… → 17,1425 ; 70 h × 17,1425 = 1 199,975 → 1 199,98.
        $this->assertRow($payslip, '1300', '70', PayslipUnit::Hours, '17.1425', PayslipUnit::EurosPerHour, '-1199.98');
        // Tenth: 29 000,00 × 12 ÷ 30 = 11 600,00 ; × 10 % = 1 160,00 < 1 199,98: the salary kept is paid.
        $this->assertRow($payslip, '1310', '70', PayslipUnit::Hours, '17.1425', PayslipUnit::EurosPerHour, '1199.98');
        // 2 600,00 × 4 % = 104,00 ; 2 600,00 + 104,00 − 1 199,98 + 1 199,98 = 2 704,00.
        self::assertSame('2704.00', (string) $payslip->gross->toDecimal());
    }

    /** Kevin Brun, December 2025: SMIC of 2025, 1 801,80, 4 overtime hours at 50 %, 6 meals. */
    public function testOvertimeAt50PercentAndMeals2025(): void
    {
        $payslip = $this->payslip('2025-12-31', $this->employee('Kevin Brun', '1801.80'), new PayslipVariables(
            quantities: ['1110' => BigDecimal::of('4'), '1410' => BigDecimal::of('6')],
        ));

        // 1 801,80 ÷ 151,67 = 11,879739… → 11,8797 ; × 1,50 = 17,81955 → 17,8196 ; × 4 h = 71,2784 → 71,28.
        $this->assertRow($payslip, '1110', '4', PayslipUnit::Hours, '17.8196', PayslipUnit::EurosPerHour, '71.28');
        // Benefit in kind, food, 2025: 5,45 € a meal × 6 = 32,70.
        $this->assertRow($payslip, '1410', '6', PayslipUnit::Count, '5.45', PayslipUnit::EurosPerUnit, '32.70');
        // 1 801,80 + 71,28 + 32,70 = 1 905,78.
        self::assertSame('1905.78', (string) $payslip->gross->toDecimal());
    }

    /** Every line explains itself: the operation with its figures, the origin of the base and of the rate. */
    public function testEveryLineExplainsItsCalculation(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('Thomas Lefèvre', '2400.00'), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('2')],
            rates: ['1010' => BigDecimal::of('3')],
        ));

        $base = $payslip->row('1000')?->explanation;
        self::assertNotNull($base);
        self::assertStringContainsString("2\u{202f}400,00", $base->formula);
        self::assertStringContainsString('151,67', $base->base);
        self::assertStringContainsString('15,8238', $base->rate);
        $bonus = $payslip->row('1010')?->explanation;
        self::assertSame("2\u{202f}400,00\u{a0}€ × 3,00\u{a0}% = 72,00\u{a0}€", $bonus?->formula);
        self::assertStringContainsString('saisi', $bonus->rate);
        // 15,8238 × 1,25 = 19,77975 → 19,7798 ; × 2 h = 39,5596 → 39,56.
        $overtime = $payslip->row('1100')?->explanation;
        self::assertSame("2,00\u{a0}h × 19,7798\u{a0}€ = 39,56\u{a0}€", $overtime?->formula);
        self::assertStringContainsString('25', $overtime->rate);
        foreach ($payslip->rows as $row) {
            self::assertNotSame('', $row->explanation->formula, $row->code);
            self::assertNotSame('', $row->explanation->base, $row->code);
        }
    }

    public function testNothingEnteredMeansNoLine(): void
    {
        $payslip = $this->payslip('2026-03-31', $this->employee('Damien Fournier', '1823.03'), PayslipVariables::none());

        self::assertSame(['1000'], $this->codes($payslip));
        self::assertSame('1823.03', (string) $payslip->gross->toDecimal());
    }

    private function employee(string $name, string $salary, string $hours = '151.67', bool $executive = false): PayslipEmployee
    {
        return new PayslipEmployee($name, $executive, ContractType::Permanent, $hours, Money::euros($salary));
    }

    private function payslip(string $day, PayslipEmployee $employee, PayslipVariables $variables): Payslip
    {
        return PayslipCalculator::calculate(new PayslipInput($employee, $variables, self::regulatoryOn($day), self::standardPlan()));
    }

    /** @return list<string> */
    private function codes(Payslip $payslip): array
    {
        return array_map(static fn ($row): string => $row->code, $payslip->rows);
    }

    private function assertRow(Payslip $payslip, string $code, ?string $base, ?PayslipUnit $baseUnit, ?string $rate, ?PayslipUnit $rateUnit, string $amount): void
    {
        $row = $payslip->row($code);
        self::assertNotNull($row, 'No line '.$code);
        self::assertSame([$base, $baseUnit, $rate, $rateUnit, $amount], [
            null === $row->base ? null : (string) $row->base,
            $row->baseUnit,
            null === $row->employeeRate ? null : (string) $row->employeeRate,
            $row->employeeRateUnit,
            (string) $row->employeeAmount->toDecimal(),
        ], 'Line '.$code);
    }
}
