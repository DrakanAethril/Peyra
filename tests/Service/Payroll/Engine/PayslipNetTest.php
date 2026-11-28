<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipEstablishment;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Engine\WithholdingTax;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The net of reference payslips (lot 4, step 4.3c): net social, net taxable, withholding tax, net to
 * pay and employer's cost, the items paid outside the social base and the deductions from the net.
 * Every expected figure was calculated apart from the engine, by the decimal script
 * reference/bulletins_4_3c.py (rates typed from plan/donnees/reglementaire/, never read from the
 * repository), and worked out in the comments.
 *
 * - Montant net social (CSS R844-1 II since 2024): gross − every employee contribution, the employer's
 *   share of the collective health and provident schemes left out.
 * - Net imposable: gross − employee contributions + non-deductible CSG and CRDS + the employer's share of
 *   the complementary health (taxable; the provident share is not, CGI art. 83) − the exempt overtime.
 * - Net à payer avant impôt: gross − employee contributions + items not subject − deductions (benefits
 *   in kind, advance); net payé: that less the withholding tax.
 */
final class PayslipNetTest extends TestCase
{
    use StandardPlanFixture;

    /** Maquette 4: Thomas Lefèvre, November 2026, after ten identical months; withholding rate 3,5 %. */
    public function testThomasLefevreOfMaquette4(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('2400.00', withholding: '3.5'), new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]), new PayslipYearToDate(
            gross: Money::euros('24720.00'),
            ceiling: Money::euros('40050.00'),
            smic: Money::euros('18230.30'),
            generalReduction: Money::euros('4387.80'),
            netTaxable: Money::euros('20253.50'),
        ));
        $totals = $payslip->totals;

        // Contributions 545,89 and 650,12 (step 4.3b, rubrique by rubrique).
        self::assertSame(['545.89', '650.12'], [$this->amount($totals->employeeContributions), $this->amount($totals->employerContributions)]);
        // Net social 2 472,00 − 545,89 = 1 926,11 (maquette 1 954,10: the definition before 2024, A-VERIFIER).
        self::assertSame('1926.11', $this->amount($totals->netSocial));
        // Net taxable 2 472,00 − 545,89 + 58,96 + 12,28 + 28,00 = 2 025,35 (maquette 2 025,35).
        self::assertSame('2025.35', $this->amount($totals->netTaxable));
        self::assertSame('0.00', $this->amount($totals->exemptOvertime));
        // Before tax 2 472,00 − 545,89 = 1 926,11 ; tax 2 025,35 × 3,5 % = 70,88725 → 70,89 ; net 1 855,22 (maquette 1 855,21).
        self::assertSame(['1926.11', '70.89', '1855.22'], [$this->amount($totals->netBeforeTax), $this->amount($totals->withholdingTax), $this->amount($totals->netPay)]);
        self::assertSame('3.5', (string) $totals->withholdingRate);
        self::assertFalse($totals->neutralRate);
        // Employer's cost 2 472,00 + 650,12 = 3 122,12 (maquette 3 122,11).
        self::assertSame('3122.12', $this->amount($totals->employerCost));
        $this->assertRow($payslip, '4200', '2025.35', '3.5', '-70.89');

        // The year so far: 27 192,00 (the maquette's « cumul brut »), 11 ceilings, 11 SMIC, 4 826,58 of reduction.
        $year = $payslip->yearToDate;
        self::assertSame(['27192.00', '44055.00', '20053.33', '4826.58', '0.00', '22278.85'], [
            $this->amount($year->gross), $this->amount($year->ceiling), $this->amount($year->smic),
            $this->amount($year->generalReduction), $this->amount($year->exemptOvertime), $this->amount($year->netTaxable),
        ]);
        // Regularised: 0,1775 × 27 192,00 = 4 826,58, less 4 387,80 already made = 438,78, as the month alone.
        self::assertSame('-438.78', $this->amount($payslip->row('2900')?->employerAmount));
    }

    /** The same month with an advance of 300,00 and 45,60 of expenses refunded: neither is taxable. */
    public function testAnAdvanceAndExpensesRefunded(): void
    {
        $payslip = $this->payslip('2026-11-30', $this->employee('2400.00', withholding: '3.5'), new PayslipVariables(
            amounts: ['3010' => Money::euros('45.60'), '4100' => Money::euros('300.00')],
            rates: ['1010' => BigDecimal::of('3')],
        ));
        $totals = $payslip->totals;

        $this->assertRow($payslip, '3010', null, null, '45.60');
        $this->assertRow($payslip, '4100', null, null, '-300.00');
        // 1 926,11 + 45,60 − 300,00 = 1 671,71 ; the tax still on 2 025,35 ; 1 671,71 − 70,89 = 1 600,82.
        self::assertSame(['2025.35', '1671.71', '70.89', '1600.82'], [$this->amount($totals->netTaxable), $this->amount($totals->netBeforeTax), $this->amount($totals->withholdingTax), $this->amount($totals->netPay)]);
        self::assertSame('3122.12', $this->amount($totals->employerCost));
    }

    /** Paul Vasseur, January 2026: executive above the ceiling, company car 322,50, withholding rate 9,9 %. */
    public function testAnExecutiveWithABenefitInKind(): void
    {
        $payslip = $this->payslip('2026-01-31', $this->employee('4650.00', executive: true, withholding: '9.9'), new PayslipVariables(amounts: ['1400' => Money::euros('322.50')]));
        $totals = $payslip->totals;

        self::assertSame(['1069.47', '2124.05'], [$this->amount($totals->employeeContributions), $this->amount($totals->employerContributions)]);
        // Net social 4 972,50 − 1 069,47 = 3 903,03.
        self::assertSame('3903.03', $this->amount($totals->netSocial));
        // Net taxable 4 972,50 − 1 069,47 + CSG/CRDS non déductibles (119,37 + 24,87) + health 28,00 = 4 075,27:
        // the employer's provident 60,08 is not taxable (the demonstration's script added it: 4 135,35).
        self::assertSame('4075.27', $this->amount($totals->netTaxable));
        // The car is in the gross pay and comes off the net: 4 972,50 − 1 069,47 − 322,50 = 3 580,53.
        $this->assertRow($payslip, '4000', '322.50', null, '-322.50');
        // Tax 4 075,27 × 9,9 % = 403,45173 → 403,45 ; net 3 580,53 − 403,45 = 3 177,08 ; cost 4 972,50 + 2 124,05 = 7 096,55.
        self::assertSame(['3580.53', '403.45', '3177.08', '7096.55'], [$this->amount($totals->netBeforeTax), $this->amount($totals->withholdingTax), $this->amount($totals->netPay), $this->amount($totals->employerCost)]);
    }

    /** Mehdi Benali, June 2026: 12 overtime hours (212,63), 15 meals on travel, no rate sent: the neutral rate of May 2026. */
    public function testExemptOvertimeMealsAndTheNeutralRate(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('2150.00'), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('12'), '3000' => BigDecimal::of('15')],
            rates: ['1010' => BigDecimal::of('7')],
        ));
        $totals = $payslip->totals;

        self::assertSame(['530.41', '561.54'], [$this->amount($totals->employeeContributions), $this->amount($totals->employerContributions)]);
        // Overtime 212,63: contributions on it 212,63 × 11,31 % = 24,05, all reduced (− 24,05) ; deductible CSG
        // 212,63 × 98,25 % × 6,80 % = 14,2061 → 14,21 ; exempt 212,63 − 14,21 = 198,42 (= 212,63 × 0,93319).
        self::assertSame('198.42', $this->amount($totals->exemptOvertime));
        // Net taxable 2 513,13 − 530,41 + 59,93 + 12,49 + 28,00 − 198,42 = 1 884,72 (CSG 2,40 % and CRDS 0,50 % of 2 497,15).
        self::assertSame('1884.72', $this->amount($totals->netTaxable));
        // Meals 15 × 10,40 € = 156,00 (not subject) ; before tax 2 513,13 − 530,41 + 156,00 = 2 138,72.
        $this->assertRow($payslip, '3000', '15', '10.4', '156.00');
        self::assertSame('2138.72', $this->amount($totals->netBeforeTax));
        // Neutral rate of May 2026: 1 807 ≤ 1 884,72 < 1 928 → 2,1 % ; 39,57912 → 39,58 ; net 2 099,14.
        self::assertSame(['2.1', '39.58', '2099.14'], [(string) $totals->withholdingRate, $this->amount($totals->withholdingTax), $this->amount($totals->netPay)]);
        self::assertTrue($totals->neutralRate);
        self::assertSame('3074.67', $this->amount($totals->employerCost));
        self::assertSame('198.42', $this->amount($payslip->yearToDate->exemptOvertime));
    }

    /** The same month after 7 400,00 of overtime already exempt this year: 100,00 left under the 7 500 € ceiling. */
    public function testTheAnnualCeilingOfExemptOvertime(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('2150.00'), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('12'), '3000' => BigDecimal::of('15')],
            rates: ['1010' => BigDecimal::of('7')],
        ), new PayslipYearToDate(exemptOvertime: Money::euros('7400.00')));
        $totals = $payslip->totals;

        // Exempt 100,00 ; net taxable 1 884,72 + 98,42 = 1 983,14 ; neutral rate 2,9 % (1 928 to 2 060) → 57,5111 → 57,51.
        self::assertSame(['100.00', '1983.14', '2.9', '57.51', '2081.21'], [$this->amount($totals->exemptOvertime), $this->amount($totals->netTaxable), (string) $totals->withholdingRate, $this->amount($totals->withholdingTax), $this->amount($totals->netPay)]);
        self::assertSame('7500.00', $this->amount($payslip->yearToDate->exemptOvertime));
    }

    /** Claire Bonnet, June 2025, after May: the reduced rates, the RGCP regularised, withholding rate 4,1 %. */
    public function testA2025Payslip(): void
    {
        $payslip = $this->payslip('2025-06-30', $this->employee('2350.00', withholding: '4.1'), new PayslipVariables(rates: ['1010' => BigDecimal::of('10')]), new PayslipYearToDate(
            gross: Money::euros('2585.00'),
            ceiling: Money::euros('3925.00'),
            smic: Money::euros('1801.80'),
            generalReduction: Money::euros('158.46'),
        ));
        $totals = $payslip->totals;

        // 0,0613 × 5 170,00 = 316,921 → 316,92, less 158,46 = 158,46.
        self::assertSame('-158.46', $this->amount($payslip->row('2900')?->employerAmount));
        // Employee 569,45 ; employer 774,98 ; net social 2 015,55 ; net taxable 2 585,00 − 569,45 + 61,63 + 12,84 + 28,00 = 2 118,02.
        self::assertSame(['569.45', '774.98', '2015.55', '2118.02'], [$this->amount($totals->employeeContributions), $this->amount($totals->employerContributions), $this->amount($totals->netSocial), $this->amount($totals->netTaxable)]);
        // Tax 2 118,02 × 4,1 % = 86,83882 → 86,84 ; net 2 015,55 − 86,84 = 1 928,71 ; cost 3 359,98.
        self::assertSame(['86.84', '1928.71', '3359.98'], [$this->amount($totals->withholdingTax), $this->amount($totals->netPay), $this->amount($totals->employerCost)]);
    }

    /** The neutral rate's grid: the lower bound included, the upper excluded, the grid in force on the payslip's day. */
    public function testTheNeutralRateGrid(): void
    {
        $may = self::regulatoryOn('2026-05-31');
        $april = self::regulatoryOn('2026-04-30');

        self::assertSame(['0', '0.5', '0.5', '43'], [
            (string) WithholdingTax::neutralRate($may, Money::euros('1634.99'))['rate'],
            (string) WithholdingTax::neutralRate($may, Money::euros('1635.00'))['rate'],
            (string) WithholdingTax::neutralRate($april, Money::euros('1620.00'))['rate'],
            (string) WithholdingTax::neutralRate($may, Money::euros('60000.00'))['rate'],
        ]);
        self::assertSame('0', (string) WithholdingTax::neutralRate($april, Money::euros('1619.99'))['rate']);
    }

    /** The net figures explain their calculation, as the lines do. */
    public function testTheNetFiguresExplainThemselves(): void
    {
        $payslip = $this->payslip('2026-06-30', $this->employee('2150.00'), new PayslipVariables(
            quantities: ['1100' => BigDecimal::of('12')],
            rates: ['1010' => BigDecimal::of('7')],
        ));
        $totals = $payslip->totals;

        self::assertStringContainsString('198,42', $totals->explanations['netTaxable']->formula);
        self::assertStringContainsString('2,10', $totals->explanations['withholdingTax']->rate);
        self::assertSame('social.withholding_tax.default_rate_grid', $totals->explanations['withholdingTax']->source?->key);
        self::assertSame('social.overtime.income_tax_exemption', $totals->explanations['exemptOvertime']->source?->key);
        foreach (['netSocial', 'netTaxable', 'exemptOvertime', 'netBeforeTax', 'withholdingTax', 'netPay', 'employerCost'] as $figure) {
            self::assertNotSame('', $totals->explanations[$figure]->formula, $figure);
            self::assertNotSame('', $totals->explanations[$figure]->base, $figure);
        }
        foreach (['3000', '4200'] as $code) {
            $row = $payslip->row($code);
            if (null !== $row) {
                self::assertNotSame('', $row->explanation->formula, $code);
            }
        }
    }

    private function employee(string $salary, string $hours = '151.67', bool $executive = false, ?string $withholding = null): PayslipEmployee
    {
        return new PayslipEmployee('Salarié', $executive, ContractType::Permanent, $hours, Money::euros($salary), null === $withholding ? null : BigDecimal::of($withholding));
    }

    private function payslip(string $day, PayslipEmployee $employee, PayslipVariables $variables, ?PayslipYearToDate $yearToDate = null, ?PayslipEstablishment $establishment = null): Payslip
    {
        return PayslipCalculator::calculate(new PayslipInput($employee, $variables, self::regulatoryOn($day), self::demoPlan(), $establishment ?? self::establishment(), $yearToDate ?? PayslipYearToDate::none()));
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
            null === $row->base ? null : (string) $row->base,
            null === $row->employeeRate ? null : (string) $row->employeeRate->strippedOfTrailingZeros(),
            (string) $row->employeeAmount->toDecimal(),
        ], 'Line '.$code);
    }
}
