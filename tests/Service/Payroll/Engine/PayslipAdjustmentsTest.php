<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Engine;

use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipForcing;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * A payslip changed by hand (step 4.4b), à la manière de Sage: a rubrique removed, a rubrique added, a
 * value forced on a line - and what follows from it calculated from the forced value. Thomas Lefèvre of
 * maquette 4 (November 2026, 2 400,00, seniority 3 %, withholding 3,5 %, no year so far); every
 * expected figure is worked out in the comments.
 */
final class PayslipAdjustmentsTest extends TestCase
{
    use StandardPlanFixture;

    public function testAForcedBaseIsWhatTheGrossAndTheContributionsReadFrom(): void
    {
        $payslip = $this->payslip(new PayslipAdjustments(forced: ['1000' => new PayslipForcing(base: BigDecimal::of('140'))]));

        // 140 h × 15,8238 (2 400,00 ÷ 151,67) = 2 215,332 → 2 215,33.
        $salary = $payslip->row('1000');
        self::assertNotNull($salary);
        self::assertSame(['140', '15.8238', '2215.33'], [(string) $salary->base, (string) $salary->employeeRate, (string) $salary->employeeAmount->toDecimal()]);
        self::assertSame(['base'], $salary->forced);
        self::assertStringStartsWith('Valeur forcée', $salary->explanation->formula);
        self::assertStringContainsString('calcul : 151,67', $salary->explanation->notes[0]);
        // Gross 2 215,33 + 72,00 = 2 287,33; old age capped 6,90 % → 157,82577 → 157,83 and 8,55 % → 195,566715 → 195,57.
        self::assertSame('2287.33', (string) $payslip->gross->toDecimal());
        $oldAge = $payslip->row('2200');
        self::assertNotNull($oldAge);
        self::assertSame(['2287.33', '157.83', '195.57'], [(string) $oldAge->base, (string) $oldAge->employeeAmount->toDecimal(), (string) $oldAge->employerAmount?->toDecimal()]);
        self::assertSame([], $oldAge->forced);
    }

    public function testAForcedRateChangesItsSideAlone(): void
    {
        $plain = $this->payslip(PayslipAdjustments::none());
        $payslip = $this->payslip(new PayslipAdjustments(forced: ['2300' => new PayslipForcing(employeeRate: BigDecimal::of('4.5'))]));

        // 2 472,00 × 4,5 % = 111,24 for the employee; the employer's 4,72 % stays: 116,6784 → 116,68.
        $row = $payslip->row('2300');
        self::assertNotNull($row);
        self::assertSame(['111.24', '116.68'], [(string) $row->employeeAmount->toDecimal(), (string) $row->employerAmount?->toDecimal()]);
        self::assertSame(['employeeRate'], $row->forced);
        // It was 3,15 %: 77,868 → 77,87. The employee's contributions grow by 111,24 − 77,87 = 33,37, the net social falls as much.
        self::assertSame('33.37', (string) $payslip->totals->employeeContributions->minus($plain->totals->employeeContributions)->toDecimal());
        self::assertSame('-33.37', (string) $payslip->totals->netSocial->minus($plain->totals->netSocial)->toDecimal());
    }

    public function testARubriqueRemovedAndAnotherAddedWithItsAmountForced(): void
    {
        $payslip = $this->payslip(new PayslipAdjustments(removed: ['1010'], added: ['1020'], forced: ['1020' => new PayslipForcing(employeeAmount: Money::euros('150'))]));

        self::assertNull($payslip->row('1010'));
        $bonus = $payslip->row('1020');
        self::assertNotNull($bonus);
        self::assertTrue($bonus->added);
        self::assertSame(['employeeAmount'], $bonus->forced);
        // 2 400,00 + 150,00 = 2 550,00.
        self::assertSame(['150.00', '2550.00'], [(string) $bonus->employeeAmount->toDecimal(), (string) $payslip->gross->toDecimal()]);
    }

    public function testARubriqueItsConditionLeavesOutCanBeAdded(): void
    {
        // Apec is for executives; added on Lefèvre's: 2 472,00 × 0,024 % = 0,593 → 0,59 and × 0,036 % = 0,890 → 0,89.
        $payslip = $this->payslip(new PayslipAdjustments(added: ['2520']));
        $apec = $payslip->row('2520');
        self::assertNotNull($apec);
        self::assertSame(['0.59', '0.89'], [(string) $apec->employeeAmount->toDecimal(), (string) $apec->employerAmount?->toDecimal()]);
        self::assertNull($this->payslip(PayslipAdjustments::none())->row('2520'));
    }

    public function testTheWithholdingTaxForcedOrRemoved(): void
    {
        $payslip = $this->payslip(new PayslipAdjustments(forced: ['4200' => new PayslipForcing(employeeRate: BigDecimal::of('5'))]));
        $totals = $payslip->totals;
        $tax = $payslip->row('4200');
        self::assertNotNull($tax);
        // Its base is the net taxable (2 025,35 as in maquette 4: no year so far changes no employee share); × 5 % = 101,2675 → 101,27.
        self::assertSame(['2025.35', '-101.27', '101.27'], [(string) $tax->base, (string) $tax->employeeAmount->toDecimal(), (string) $totals->withholdingTax->toDecimal()]);
        self::assertSame('5', (string) $totals->withholdingRate);
        // 1 926,11 − 101,27 = 1 824,84.
        self::assertSame('1824.84', (string) $totals->netPay->toDecimal());

        $none = $this->payslip(new PayslipAdjustments(removed: ['4200']))->totals;
        self::assertSame(['0.00', '1926.11'], [(string) $none->withholdingTax->toDecimal(), (string) $none->netPay->toDecimal()]);
    }

    public function testTheGeneralReductionIsNeverForced(): void
    {
        $plain = $this->payslip(PayslipAdjustments::none())->row('2900');
        $forced = $this->payslip(new PayslipAdjustments(forced: ['2900' => new PayslipForcing(employerAmount: Money::euros('10'))]))->row('2900');
        self::assertNotNull($plain);
        self::assertNotNull($forced);
        self::assertTrue($plain->employerAmount?->equals($forced->employerAmount ?? Money::zero()));
        self::assertSame([], $forced->forced);
    }

    public function testTheAdjustmentsAreKeptAsTheyWereGiven(): void
    {
        $adjustments = (new PayslipAdjustments(forced: ['1000' => new PayslipForcing(base: BigDecimal::of('140'), employeeAmount: Money::euros('2200.5'))]))
            ->withRemoved('1010')->withAdded('1020')->withAdded('1010');

        self::assertSame([], $adjustments->removed);
        self::assertSame(['1020'], $adjustments->added);
        $array = $adjustments->toArray();
        self::assertSame(['removed' => [], 'added' => ['1020'], 'forced' => ['1000' => ['base' => '140', 'employeeAmount' => '2200.50']]], $array);
        self::assertEquals($adjustments, PayslipAdjustments::fromArray(json_decode((string) json_encode($array), true, flags: \JSON_THROW_ON_ERROR)));
        // A rubrique removed loses what was forced on it.
        self::assertSame([], $adjustments->withRemoved('1000')->forced);
    }

    public function testARubriqueAddedThenTakenOffIsNoLongerAdjusted(): void
    {
        $adjustments = (new PayslipAdjustments())->withAdded('1100')->withRemoved('1100');

        self::assertSame([], $adjustments->removed);
        self::assertSame([], $adjustments->added);
        self::assertTrue($adjustments->isEmpty());
    }

    private function payslip(PayslipAdjustments $adjustments): Payslip
    {
        $employee = new PayslipEmployee('Thomas Lefèvre', false, ContractType::Permanent, '151.67', Money::euros('2400.00'), BigDecimal::of('3.5'));

        return PayslipCalculator::calculate(new PayslipInput($employee, new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]), self::regulatoryOn('2026-11-30'), self::demoPlan(), self::establishment(), PayslipYearToDate::none(), $adjustments));
    }
}
