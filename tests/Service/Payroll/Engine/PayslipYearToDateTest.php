<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The progressive regularisation over the year (lot 4, step 4.3c): the brackets of the ceiling and the
 * general reduction are calculated on the year so far - the gross pay, the ceilings, the SMIC of
 * reference and the reduction already made, before this payslip - and the month takes the difference.
 * Expected figures from the decimal script reference/bulletins_4_3c.py, worked out in the comments.
 * Non-executive, Menuiserie Vasseur's establishment (below 11, 20 and 50), its complementary health.
 */
final class PayslipYearToDateTest extends TestCase
{
    use StandardPlanFixture;

    /** February 5 000,00 after January 3 000,00: the January ceiling left unused takes February's excess. */
    public function testTheCeilingLeftUnusedIsCarriedForward(): void
    {
        $payslip = $this->payslip('2026-02-28', '5000.00', new PayslipYearToDate(
            gross: Money::euros('3000.00'),
            ceiling: Money::euros('4005.00'),
            smic: Money::euros('1823.03'),
            generalReduction: Money::euros('299.70'),
        ));

        // Tranche 1 of the year: min(8 000,00 ; 8 010,00) − min(3 000,00 ; 4 005,00) = 5 000,00 ; no tranche 2.
        $this->assertBase($payslip, '2200', '5000.00', '345.00', '427.50');
        $this->assertBase($payslip, '2300', '5000.00', '157.50', '236.00');
        $this->assertBase($payslip, '2610', '5000.00', '0.00', '5.00');
        self::assertNull($payslip->row('2320'));
        // CET: 8 000,00 does not exceed 8 010,00 for the year.
        self::assertNull($payslip->row('2340'));
        // RGDU on the year: (3 × 3 646,06 ÷ 8 000,00 − 1) ÷ 2 = 0,183637 ; ^1,75 = 0,051418 ; 0,02 + 0,3781 × 0,051418 =
        // 0,039441 → 0,0395 ; 8 000,00 × 0,0395 = 316,00, less 299,70 = 16,30.
        self::assertSame(['0.0395', '16.30', '13.84', '2.46'], $this->reduction($payslip));
        self::assertSame('-16.30', (string) $payslip->row('2900')?->employerAmount?->toDecimal());
        self::assertSame(['8000.00', '8010.00', '3646.06', '316.00'], $this->year($payslip));
        // Without the year so far, the same month has a tranche 2 of 995,00 and a CET.
        $alone = $this->payslip('2026-02-28', '5000.00', PayslipYearToDate::none());
        $this->assertBase($alone, '2320', '995.00', '85.97', '128.85');
        $this->assertBase($alone, '2340', '5000.00', '7.00', '10.50');
    }

    /** February 2 000,00 after January 6 000,00: January's tranche 2 comes back into tranche 1. */
    public function testATranche2ComesBack(): void
    {
        $payslip = $this->payslip('2026-02-28', '2000.00', new PayslipYearToDate(
            gross: Money::euros('6000.00'),
            ceiling: Money::euros('4005.00'),
            smic: Money::euros('1823.03'),
        ));

        // Tranche 1: min(8 000 ; 8 010) − min(6 000 ; 4 005) = 3 995,00 ; tranche 2: 0 − 1 995,00 = − 1 995,00.
        $this->assertBase($payslip, '2200', '3995.00', '275.66', '341.57');
        $this->assertBase($payslip, '2310', '3995.00', '34.36', '51.54');
        // − 1 995,00 × 8,64 % = − 172,368 → − 172,37 ; × 12,95 % = − 258,3525 → − 258,35 ; CEG 1,08 % → − 21,55, 1,62 % → − 32,32.
        $this->assertBase($payslip, '2320', '-1995.00', '-172.37', '-258.35');
        $this->assertBase($payslip, '2330', '-1995.00', '-21.55', '-32.32');
        // January had no reduction (6 000,00 ≥ 3 SMIC); the year now has one: 8 000,00 × 0,0395 = 316,00 ;
        // Urssaf 316,00 × 0,3380 ÷ 0,3981 = 268,2944 → 268,29, Agirc-Arrco 47,71.
        self::assertSame(['0.0395', '316.00', '268.29', '47.71'], $this->reduction($payslip));
        self::assertSame(['471.26', '572.12'], [(string) $payslip->totals->employeeContributions->toDecimal(), (string) $payslip->totals->employerContributions->toDecimal()]);
    }

    /** March 6 000,00 after two months at the SMIC: the reduction of the year falls, the month gives some back. */
    public function testTheReductionIsRegularisedDownwards(): void
    {
        $payslip = $this->payslip('2026-03-31', '6000.00', new PayslipYearToDate(
            gross: Money::euros('3646.06'),
            ceiling: Money::euros('8010.00'),
            smic: Money::euros('3646.06'),
            generalReduction: Money::euros('1451.50'),
        ));

        // Tranche 1 of the year: 9 646,06 ≤ 12 015,00, all of March in tranche 1.
        $this->assertBase($payslip, '2200', '6000.00', '414.00', '513.00');
        self::assertNull($payslip->row('2320'));
        // (3 × 5 469,09 ÷ 9 646,06 − 1) ÷ 2 = 0,350484 ; ^1,75 = 0,159839 ; 0,02 + 0,3781 × 0,159839 = 0,080435 → 0,0804 ;
        // 9 646,06 × 0,0804 = 775,543 → 775,54 ; less 1 451,50 already made: − 675,96, a reintegration of 675,96.
        self::assertSame(['0.0804', '-675.96', '-573.91', '-102.05'], $this->reduction($payslip));
        self::assertSame('675.96', (string) $payslip->row('2900')?->employerAmount?->toDecimal());
        self::assertSame(['1281.13', '3278.92'], [(string) $payslip->totals->employeeContributions->toDecimal(), (string) $payslip->totals->employerContributions->toDecimal()]);
        self::assertSame(['9646.06', '12015.00', '5469.09', '775.54'], $this->year($payslip));
        self::assertStringContainsString("1\u{202f}451,50", implode(' ', $payslip->row('2900')->explanation->notes ?? []));
    }

    /** February 9 200,00 after a month at the SMIC: the year reaches 3 SMIC, the month takes back January's reduction. */
    public function testTheReductionOfTheYearEnds(): void
    {
        $payslip = $this->payslip('2026-02-28', '9200.00', new PayslipYearToDate(
            gross: Money::euros('1823.03'),
            ceiling: Money::euros('4005.00'),
            smic: Money::euros('1823.03'),
            generalReduction: Money::euros('725.75'),
        ));

        // 11 023,03 ≥ 3 × 3 646,06 = 10 938,18: no reduction for the year, the 725,75 of January given back
        // (Urssaf 725,75 × 0,3380 ÷ 0,3981 = 616,19).
        self::assertSame(['0', '-725.75', '-616.19', '-109.56'], $this->reduction($payslip));
        self::assertSame('725.75', (string) $payslip->row('2900')?->employerAmount?->toDecimal());
        self::assertSame('0.00', (string) $payslip->yearToDate->generalReduction->toDecimal());
    }

    private function payslip(string $day, string $salary, PayslipYearToDate $yearToDate): Payslip
    {
        $employee = new PayslipEmployee('Salarié', false, ContractType::Permanent, '151.67', Money::euros($salary), null);

        return PayslipCalculator::calculate(new PayslipInput($employee, PayslipVariables::none(), self::regulatoryOn($day), self::demoPlan(), self::establishment(), $yearToDate));
    }

    private function assertBase(Payslip $payslip, string $code, string $base, string $employee, string $employer): void
    {
        $row = $payslip->row($code);
        self::assertNotNull($row, 'No line '.$code);
        self::assertSame([$base, $employee, $employer], [(string) $row->base, (string) $row->employeeAmount->toDecimal(), (string) $row->employerAmount?->toDecimal()], 'Line '.$code);
    }

    /** @return list<string> the coefficient, the month's amount, its Urssaf and Agirc-Arrco shares */
    private function reduction(Payslip $payslip): array
    {
        $reduction = $payslip->generalReduction;
        self::assertNotNull($reduction);

        return [(string) $reduction->coefficient, (string) $reduction->amount->toDecimal(), (string) $reduction->urssafShare->toDecimal(), (string) $reduction->retirementShare->toDecimal()];
    }

    /** @return list<string> the year to date after the payslip: gross, ceiling, SMIC of reference, general reduction */
    private function year(Payslip $payslip): array
    {
        $year = $payslip->yearToDate;

        return [(string) $year->gross->toDecimal(), (string) $year->ceiling->toDecimal(), (string) $year->smic->toDecimal(), (string) $year->generalReduction->toDecimal()];
    }
}
