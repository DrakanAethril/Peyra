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
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The review of lot 4 (step 4.7a): three payslips of January 2026 recalculated by a script written for the
 * review, which reads neither the engine nor the earlier reference script - `reference/revue_4_7.py`. The
 * figures below are what that script prints; the engine must give them to the cent: a non-executive at the
 * SMIC with the general reduction at its maximum, an executive above the ceiling, an apprentice.
 */
final class IndependentRecalculationTest extends TestCase
{
    use StandardPlanFixture;

    /**
     * gross, employee contributions, employer contributions (reduction deducted), general reduction and its
     * coefficient, CSG base, net social, net taxable, apprentice's exemption, withholding tax, net pay, employer cost.
     */
    public static function cases(): iterable
    {
        yield 'Kevin Brun, non-cadre au SMIC' => [
            new PayslipEmployee('Kevin Brun', false, ContractType::Permanent, '151.67', Money::euros('1823.03'), BigDecimal::of('0'), new \DateTimeImmutable('2024-03-04')),
            ['1823.03', '410.65', '84.63', '725.75', '0.3981', '1819.13', '1412.38', '1493.14', '0.00', '0.00', '1412.38', '1907.66'],
        ];
        yield 'Paul Vasseur, cadre au-dessus du plafond' => [
            new PayslipEmployee('Paul Vasseur', true, ContractType::Permanent, '151.67', Money::euros('4650.00'), BigDecimal::of('9.9'), new \DateTimeImmutable('2012-09-03')),
            ['4650.00', '1005.58', '1976.45', '118.11', '0.0254', '4656.71', '3644.42', '3807.46', '0.00', '376.94', '3267.48', '6626.45'],
        ];
        yield 'Hugo Marchand, apprenti' => [
            new PayslipEmployee('Hugo Marchand', false, ContractType::Apprenticeship, '151.67', Money::euros('492.22'), null, new \DateTimeImmutable('2025-09-01')),
            ['492.22', '30.71', '43.30', '195.95', '0.3981', '28.00', '461.51', '0.00', '490.32', '0.00', '461.51', '535.52'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('cases')]
    public function testTheEngineMeetsTheReviewScript(PayslipEmployee $employee, array $expected): void
    {
        $payslip = PayslipCalculator::calculate(new PayslipInput($employee, new PayslipVariables(), self::regulatoryOn('2026-01-31'), self::demoPlan(), self::establishment(), PayslipYearToDate::none()));

        self::assertSame($expected, self::figures($payslip));
    }

    /** @return list<string> */
    private static function figures(Payslip $payslip): array
    {
        $totals = $payslip->totals;
        $reduction = $payslip->generalReduction;
        $money = static fn (?Money $amount): string => (string) ($amount ?? Money::zero())->toDecimal();

        return [
            $money($payslip->gross), $money($totals->employeeContributions), $money($totals->employerContributions),
            $money($reduction?->amount), (string) ($reduction->coefficient ?? '0'), (string) ($payslip->row('2800')->base ?? '0'),
            $money($totals->netSocial), $money($totals->netTaxable), $money($totals->apprenticeExempt), $money($totals->withholdingTax), $money($totals->netPay), $money($totals->employerCost),
        ];
    }
}
