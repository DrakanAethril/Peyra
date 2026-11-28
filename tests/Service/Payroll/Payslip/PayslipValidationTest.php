<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Payslip;

use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipForcing;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Payslip\PayslipLineCells;
use App\Service\Payroll\Payslip\PayslipPrintLines;
use App\Service\Payroll\Payslip\PayslipSnapshot;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\Money;
use App\Tests\Service\Payroll\Engine\StandardPlanFixture;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * What step 4.4b adds around the engine, on Thomas Lefèvre of maquette 4 (November 2026, 2 400,00,
 * seniority 3 %, withholding 3,5 %): the frozen payslip read back as it was and the month's figures the
 * year to date sums; the grid's cells - which are open, what is read from what was typed -, the
 * rubriques that are not removed; the clarified model's grouping for the PDF. Figures worked out in
 * the comments.
 */
final class PayslipValidationTest extends TestCase
{
    use StandardPlanFixture;

    public function testAFrozenPayslipReadsBackAsItWas(): void
    {
        $before = new PayslipYearToDate(gross: Money::euros('24720.00'), ceiling: Money::euros('40050.00'), smic: Money::euros('18230.30'), generalReduction: Money::euros('4387.80'), netTaxable: Money::euros('20253.50'));
        $payslip = $this->payslip(PayslipAdjustments::none(), $before);
        $frozen = json_decode((string) json_encode(PayslipSnapshot::toArray($payslip, $before)), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($frozen));

        self::assertEquals($payslip, PayslipSnapshot::fromArray($frozen));
        // The month added 2 472,00 of gross pay, a ceiling of 4 005,00 and 438,78 of reduction (4 826,58 − 4 387,80).
        $month = PayslipSnapshot::monthFigures($frozen);
        self::assertSame([247200, 400500, 43878], [$month['gross'], $month['ceiling'], $month['generalReduction']]);
        // Two months summed: 4 944,00 of gross pay, 8 010,00 of ceilings.
        $year = PayslipSnapshot::yearToDateOf([$month, $month]);
        self::assertSame(['4944.00', '8010.00'], [(string) $year->gross->toDecimal(), (string) $year->ceiling->toDecimal()]);
    }

    public function testTheOpenCellsOfALine(): void
    {
        $payslip = $this->payslip(new PayslipAdjustments(added: ['1020']));
        $plan = $this->plan();

        self::assertSame(['base', 'employeeRate'], PayslipLineCells::open($this->row($payslip, '1000'), $plan['1000']));
        // An amount without a rate: its amount is typed.
        self::assertSame(['employeeAmount'], PayslipLineCells::open($this->row($payslip, '1020'), $plan['1020']));
        // A contribution of the employer alone: its base and its employer's rate.
        self::assertSame(['base', 'employerRate'], PayslipLineCells::open($this->row($payslip, '2000'), $plan['2000']));
        self::assertSame(['base', 'employeeRate', 'employerRate'], PayslipLineCells::open($this->row($payslip, '2200'), $plan['2200']));
        // The general reduction is the year's calculation: nothing is typed on it.
        self::assertSame([], PayslipLineCells::open($this->row($payslip, '2900'), $plan['2900']));
        self::assertSame('151,67', PayslipLineCells::text($this->row($payslip, '1000'), 'base'));
        self::assertSame('15,8238', PayslipLineCells::text($this->row($payslip, '1000'), 'employeeRate'));
    }

    public function testWhatIsTypedInTheCells(): void
    {
        $payslip = $this->payslip(new PayslipAdjustments(forced: ['2300' => new PayslipForcing(employeeRate: BigDecimal::of('4.5'))]));
        $current = new PayslipAdjustments(forced: ['2300' => new PayslipForcing(employeeRate: BigDecimal::of('4.5'))]);
        $shown = ['1000.base' => '151,67', '2300.employeeRate' => '4,50', '2200.employerRate' => '8,55'];

        // A cell changed is forced, a cell emptied goes back to the calculation, a cell as shown keeps what it was.
        $read = PayslipLineCells::read($payslip->rows, array_values($this->plan()), $current, ['1000.base' => '140', '2300.employeeRate' => '', '2200.employerRate' => '8,55'], $shown);
        self::assertSame([], $read['errors']);
        self::assertSame(['1000' => ['base' => '140']], $read['adjustments']->toArray()['forced']);

        $read = PayslipLineCells::read($payslip->rows, array_values($this->plan()), $current, ['1000.base' => '-3', '2200.employerRate' => '8,55555', '2300.employeeRate' => 'cent'], $shown);
        self::assertSame([
            '1000.base' => 'Saisissez la valeur sans signe : la rubrique dit si elle s’ajoute ou se retire.',
            '2200.employerRate' => 'Un taux s’écrit avec quatre décimales au plus.',
            '2300.employeeRate' => 'Ce nombre n’est pas lisible. Écrivez par exemple 6,90.',
        ], $read['errors']);
        self::assertSame($current, $read['adjustments']);
    }

    public function testTheRubriquesTheLawMakesDue(): void
    {
        $plan = $this->plan();
        foreach (['1000', '2000', '2100', '2200', '2900', '4200'] as $due) {
            self::assertFalse(PayslipLineCells::removable($plan[$due]), $due);
        }
        foreach (['1010', '1020', '1500', '2010', '4100'] as $removable) {
            self::assertTrue(PayslipLineCells::removable($plan[$removable]), $removable);
        }
    }

    public function testTheClarifiedModelGroupsTheContributions(): void
    {
        $lines = PayslipPrintLines::of($this->payslip(PayslipAdjustments::none()));

        self::assertSame(['Salaire de base', 'Prime d’ancienneté'], array_column($lines['gross'], 'label'));
        $retirement = array_values(array_filter($lines['contributions'], static fn (array $block): bool => 'Retraite' === $block['heading']))[0];
        $tranche1 = array_values(array_filter($retirement['lines'], static fn (array $line): bool => 'Complémentaire tranche 1' === $line['label']))[0];
        // 2300 and 2310 on 2 472,00: 3,15 + 0,86 = 4,01 % and 4,72 + 1,29 = 6,01 %; 77,87 + 21,26 = 99,13 and 116,68 + 31,89 = 148,57 (maquette 4).
        self::assertSame(['2472.00', '4.01', '99.13', '6.01', '148.57'], [
            (string) $tranche1['base'], (string) $tranche1['employeeRate'], (string) $tranche1['employeeAmount']?->toDecimal(),
            (string) $tranche1['employerRate'], (string) $tranche1['employerAmount']?->toDecimal(),
        ]);
        // The withholding tax is the foot's, not a line.
        $after = array_merge(...array_map(static fn (array $block): array => array_column($block['lines'], 'label'), $lines['after']));
        self::assertNotContains('Prélèvement à la source', $after);
    }

    private function payslip(PayslipAdjustments $adjustments, ?PayslipYearToDate $before = null): Payslip
    {
        $employee = new PayslipEmployee('Thomas Lefèvre', false, ContractType::Permanent, '151.67', Money::euros('2400.00'), BigDecimal::of('3.5'));

        return PayslipCalculator::calculate(new PayslipInput($employee, new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]), self::regulatoryOn('2026-11-30'), self::demoPlan(), self::establishment(), $before ?? PayslipYearToDate::none(), $adjustments));
    }

    /** @return array<array-key, PayrollItemSpec> by code */
    private function plan(): array
    {
        $plan = [];
        foreach (self::demoPlan() as $spec) {
            $plan[$spec->code] = $spec;
        }

        return $plan;
    }

    private function row(Payslip $payslip, string $code): \App\Service\Payroll\Engine\PayslipRow
    {
        return $payslip->row($code) ?? throw new \LogicException('No line '.$code);
    }
}
