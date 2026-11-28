<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Service\Payroll\Engine\PaidLeave;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Engine\Severance;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The apprentice and the last payslip of a contract (lot 4, step 4.3e). Every expected figure was
 * calculated apart from the engine by the decimal script reference/bulletins_4_3c.py (cases 13 to 19),
 * from these rules (service-public F2918, F11249, F803, F987, F408):
 *
 * - an apprentice whose contract started on or after 1 March 2025 pays the employee contributions the law
 *   sets, the CSG and the CRDS on the salary above 50 % of the monthly SMIC; before, the contributions above
 *   79 % and no CSG nor CRDS; the share is prorated over the days of a month the contract does not cover
 *   whole; the salary is exempt from income tax within the annual SMIC, used up over the year;
 * - a fixed-term contract reaching its term earns 10 % of its gross pay; the paid leave left is paid the
 *   better of the salary kept and the tenth of a reference that includes the last month and that indemnity;
 * - the legal dismissal indemnity is a quarter of a month a year up to ten years, a third beyond; an
 *   indemnity is exempt from contributions within the income tax exemption and two PASS, the CSG and the
 *   CRDS due without abatement above the legal amount, and a conventional termination costs the employer
 *   30 % (2025) then 40 % (2026) of its exempt part.
 */
final class PayslipEndOfContractTest extends TestCase
{
    use StandardPlanFixture;

    /** Hugo Marchand, November 2026: second year (51 % of 1 867,02), contract of 1 September 2025, no health insurance. */
    public function testAnApprenticeOfTheNewRegime(): void
    {
        $employee = new PayslipEmployee('Hugo Marchand', false, ContractType::Apprenticeship, '151.67', Money::euros('952.18'), hiredOn: new \DateTimeImmutable('2025-09-01'));
        $payslip = $this->payslip('2026-11-30', $employee, PayslipVariables::none(), standard: true);

        // Exempt part 50 % × 1 867,02 = 933,51 ; the employee's base 952,18 − 933,51 = 18,67, the employer's whole.
        $row = $payslip->row('2200');
        self::assertSame(['952.18', '18.67', '1.29', '81.41'], [(string) $row?->base, (string) $row?->employeeBase, $this->amount($row?->employeeAmount), $this->amount($row?->employerAmount)]);
        self::assertSame(['0.07', '0.59', '0.16'], [$this->amount($payslip->row('2210')?->employeeAmount), $this->amount($payslip->row('2300')?->employeeAmount), $this->amount($payslip->row('2310')?->employeeAmount)]);
        self::assertStringContainsString('933,51', $row->explanation->notes[0] ?? '');
        // CSG base: 18,67 × 98,25 % = 18,34 ; 1,25 + 0,44 + 0,09.
        $this->assertRow($payslip, '2800', '18.34', '6.8', '1.25');
        self::assertSame(['0.44', '0.09'], [$this->amount($payslip->row('2810')?->employeeAmount), $this->amount($payslip->row('2820')?->employeeAmount)]);
        // Net taxable 948,82 before the exemption, all of it exempt: nothing to withhold.
        self::assertSame(['3.89', '29.57', '948.29', '0.00', '0.00', '948.29', '981.75'], $this->figures($payslip));
        self::assertSame(['948.82', '948.82'], [$this->amount($payslip->totals->apprenticeExempt), $this->amount($payslip->yearToDate->apprenticeExempt)]);
        self::assertArrayHasKey('apprenticeExempt', $payslip->totals->explanations);
    }

    /** A contract started before 1 March 2025, 2 000,00 a month, December 2025 after eleven identical months. */
    public function testAnApprenticeOfTheFormerRegimeReachesTheAnnualLimit(): void
    {
        $employee = new PayslipEmployee('Apprenti', false, ContractType::Apprenticeship, '151.67', Money::euros('2000.00'), BigDecimal::of('0'), new \DateTimeImmutable('2024-09-01'));
        $before = new PayslipYearToDate(
            gross: Money::euros('22000.00'),
            ceiling: Money::euros('43175.00'),
            smic: Money::euros('19819.80'),
            generalReduction: Money::euros('5167.80'),
            netTaxable: Money::euros('0.00'),
            apprenticeExempt: Money::euros('21282.69'),
        );
        $payslip = $this->payslip('2025-12-31', $employee, PayslipVariables::none(), $before, standard: true);

        // 79 % × 1 801,80 = 1 423,42 exempt: 2 000,00 − 1 423,42 = 576,58 × 6,90 % = 39,78 ; no CSG nor CRDS.
        $row = $payslip->row('2200');
        self::assertSame(['576.58', '39.78'], [(string) $row?->employeeBase, $this->amount($row?->employeeAmount)]);
        self::assertNull($payslip->row('2800'));
        self::assertNull($payslip->row('2820'));
        // Net taxable 1 934,79 before the exemption; 21 622,00 − 21 282,69 = 339,31 left of the year's limit.
        self::assertSame(['65.21', '230.72', '1934.79', '1595.48', '0.00', '1934.79', '2230.72'], $this->figures($payslip));
        self::assertSame(['339.31', '21622.00'], [$this->amount($payslip->totals->apprenticeExempt), $this->amount($payslip->yearToDate->apprenticeExempt)]);
    }

    /** An apprentice hired on 15 June 2026 (16 days of 30), 53 % of 1 867,02 = 989,52: the exempt part is prorated. */
    public function testAnApprenticeHiredDuringTheMonth(): void
    {
        $employee = new PayslipEmployee('Apprentie', false, ContractType::Apprenticeship, '151.67', Money::euros('989.52'), BigDecimal::of('0'), new \DateTimeImmutable('2026-06-15'));
        $payslip = $this->payslip('2026-06-30', $employee, PayslipVariables::none(), standard: true);

        // 14 × 989,52 ÷ 30 = 32,9840 → 461,78 ; gross 527,74. Exempt part 1 867,02 × 50 % × 16 ÷ 30 = 497,87.
        $this->assertRow($payslip, '1230', '14', '32.984', '-461.78');
        self::assertSame(['29.87', '2.06'], [(string) $payslip->row('2200')?->employeeBase, $this->amount($payslip->row('2200')?->employeeAmount)]);
        $this->assertRow($payslip, '2800', '29.35', '6.8', '2.00');
        self::assertSame(['6.23', '16.40', '521.51', '0.00', '0.00', '521.51', '544.14'], $this->figures($payslip));
    }

    /** Yanis Chabert, fixed-term contract from 1 April to 30 September 2026, 1 950,00 a month, 15 days of leave left. */
    public function testTheLastPayslipOfAFixedTermContract(): void
    {
        $employee = new PayslipEmployee('Yanis Chabert', false, ContractType::FixedTerm, '151.67', Money::euros('1950.00'), BigDecimal::of('2.1'), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-09-30'), ExitReason::FixedTermEnd);
        $variables = new PayslipVariables(amounts: ['1500' => Money::euros('9750.00')], compensatoryLeave: new PaidLeave('87.5', '15', '15', Money::euros('9750.00')));
        $payslip = $this->payslip('2026-09-30', $employee, $variables);

        // 10 % × (9 750,00 + 1 950,00) = 1 170,00 ; leave: 10 % × (9 750,00 + 1 950,00 + 1 170,00) = 1 287,00 > 87,5 h × 12,8569.
        $this->assertRow($payslip, '1500', '11700', '10', '1170.00');
        $this->assertRow($payslip, '1320', '12870', '10', '1287.00');
        self::assertSame(['1000', '1320', '1500'], array_slice(array_map(static fn ($row): string => $row->code, $payslip->rows), 0, 3));
        self::assertSame('4407.00', $this->amount($payslip->gross));
        self::assertSame(['950.54', '1799.09', '3456.46', '3610.84', '75.83', '3380.63', '6206.09'], $this->figures($payslip));
    }

    /** No end-of-contract indemnity for an early termination, nor before the last month. */
    public function testTheFixedTermIndemnityIsDueAtTheTermOnly(): void
    {
        $variables = new PayslipVariables(amounts: ['1500' => Money::euros('9750.00')]);
        $early = new PayslipEmployee('CDD', false, ContractType::FixedTerm, '151.67', Money::euros('1950.00'), BigDecimal::of('0'), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-09-30'), ExitReason::EarlyTermination);
        self::assertNull($this->payslip('2026-09-30', $early, $variables)->row('1500'));
        $term = new PayslipEmployee('CDD', false, ContractType::FixedTerm, '151.67', Money::euros('1950.00'), BigDecimal::of('0'), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-09-30'), ExitReason::FixedTermEnd);
        self::assertNull($this->payslip('2026-08-31', $term, $variables)->row('1500'));
    }

    /** A dismissal on 30 November 2026 after 12 years and 9 months, reference salary 2 600,00. */
    public function testTheLegalDismissalIndemnity(): void
    {
        $employee = new PayslipEmployee('Salarié', false, ContractType::Permanent, '151.67', Money::euros('2600.00'), BigDecimal::of('6'), new \DateTimeImmutable('2014-03-01'), new \DateTimeImmutable('2026-11-30'), ExitReason::PersonalDismissal);
        $payslip = $this->payslip('2026-11-30', $employee, new PayslipVariables(severance: new Severance(Money::euros('2600.00'))));

        // 2 600 × 1/4 × 10 + 2 600 × 1/3 × 2,75 = 6 500,00 + 2 383,333… = 8 883,33, exempt whole: no CSG.
        $this->assertRow($payslip, '3100', null, null, '8883.33');
        self::assertNull($payslip->row('2830'));
        self::assertNull($payslip->row('3110'));
        self::assertStringContainsString('12 ans 9 mois', $payslip->row('3100')->explanation->base ?? '');
        self::assertSame(['572.56', '744.46', '2027.44', '2130.33', '127.82', '10782.95', '3344.46'], $this->figures($payslip));
    }

    /** The two examples of service-public F987 (reference salary 1 500,00): 3 years 6 months, 12 years 9 months. */
    public function testTheExamplesOfTheOfficialPage(): void
    {
        $legal = function (string $hired): string {
            $employee = new PayslipEmployee('Salarié', false, ContractType::Permanent, '151.67', Money::euros('1500.00'), BigDecimal::of('0'), new \DateTimeImmutable($hired), new \DateTimeImmutable('2026-04-30'), ExitReason::EconomicDismissal);

            return $this->amount($this->payslip('2026-04-30', $employee, new PayslipVariables(severance: new Severance(Money::euros('1500.00'))))->row('3100')?->employeeAmount);
        };

        self::assertSame(['1312.50', '5125.00', 'null'], [$legal('2022-11-01'), $legal('2013-08-01'), $legal('2025-10-01')]);
    }

    /** A conventional termination on 31 October 2025: legal 4 875,00 (6 years 6 months of 3 000,00), 7 000,00 agreed. */
    public function testAConventionalTerminationIn2025(): void
    {
        $payslip = $this->conventional('2025');

        $this->assertRow($payslip, '3110', null, null, '7000.00');
        // CSG and CRDS without abatement on 7 000,00 − 4 875,00 = 2 125,00 ; the employer's 30 % on 7 000,00.
        $this->assertRow($payslip, '2830', '2125', '6.8', '144.50');
        $this->assertRow($payslip, '2840', '2125', '2.4', '51.00');
        $this->assertRow($payslip, '2850', '2125', '0.5', '10.63');
        self::assertSame(['7000.00', '30', '2100.00'], [(string) $payslip->row('2710')?->base, (string) $payslip->row('2710')?->employerRate, $this->amount($payslip->row('2710')?->employerAmount)]);
        self::assertSame(['862.05', '3178.78', '2137.95', '2458.37', '184.38', '8953.57', '6178.78'], $this->figures($payslip));
    }

    /** The same in 2026: the employer's contribution is 40 %. */
    public function testAConventionalTerminationIn2026(): void
    {
        $payslip = $this->conventional('2026');

        $this->assertRow($payslip, '2830', '2125', '6.8', '144.50');
        self::assertSame(['40', '2800.00'], [(string) $payslip->row('2710')?->employerRate, $this->amount($payslip->row('2710')?->employerAmount)]);
        self::assertSame(['862.05', '3815.78', '2137.95', '2458.37', '184.38', '8953.57', '6815.78'], $this->figures($payslip));
    }

    /** Below the legal amount, or with a part subject to contributions: the engine refuses rather than guess. */
    public function testWhatTheEngineRefuses(): void
    {
        try {
            $this->conventional('2026', '4000.00');
            self::fail('An agreed indemnity below the legal amount.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('875,00', $refused->getMessage());
        }

        $this->expectException(\LogicException::class);
        // 2 × 30 000,00 = 60 000,00 exempt of 70 000,00: 10 000,00 would be subject to contributions, said as outside the scope.
        $this->expectExceptionMessage('ce cas est hors du périmètre de Peyra');
        $this->conventional('2026', '70000.00', '30000.00');
    }

    /** No rubrique stands on a code: each new base is in the standard plan once. */
    public function testTheStandardPlanCarriesTheEndOfAContract(): void
    {
        $bases = [];
        foreach (self::standardPlan() as $spec) {
            $bases[$spec->base->value][] = $spec->code;
        }

        self::assertSame([['1320'], ['1500'], ['3100'], ['3110'], ['2830', '2840', '2850'], ['2710']], [$bases['leave_compensation'], $bases['fixed_term_end'], $bases['dismissal'], $bases['conventional_break'], $bases['severance_csg'], $bases['conventional_exempt']]);
        self::assertCount(58, self::standardPlan(), implode(' ', array_map(static fn (PayrollItemSpec $spec): string => $spec->code, self::standardPlan())));
    }

    private function conventional(string $year, string $agreed = '7000.00', string $previous = '36000.00'): Payslip
    {
        $hired = '2025' === $year ? '2019-05-01' : '2020-05-01';
        $employee = new PayslipEmployee('Salarié', false, ContractType::Permanent, '151.67', Money::euros('3000.00'), BigDecimal::of('7.5'), new \DateTimeImmutable($hired), new \DateTimeImmutable($year.'-10-31'), ExitReason::ConventionalTermination);

        return $this->payslip($year.'-10-31', $employee, new PayslipVariables(severance: new Severance(Money::euros('3000.00'), Money::euros($agreed), Money::euros($previous))));
    }

    private function payslip(string $day, PayslipEmployee $employee, PayslipVariables $variables, ?PayslipYearToDate $yearToDate = null, bool $standard = false): Payslip
    {
        return PayslipCalculator::calculate(new PayslipInput($employee, $variables, self::regulatoryOn($day), $standard ? self::standardPlan() : self::demoPlan(), self::establishment(), $yearToDate ?? PayslipYearToDate::none()));
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
