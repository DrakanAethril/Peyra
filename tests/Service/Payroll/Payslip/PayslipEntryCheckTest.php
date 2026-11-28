<?php

declare(strict_types=1);

namespace App\Tests\Service\Payroll\Payslip;

use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Payroll\ContractType;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipCalculator;
use App\Service\Payroll\Engine\PayslipEmployee;
use App\Service\Payroll\Engine\PayslipForcing;
use App\Service\Payroll\Engine\PayslipInput;
use App\Service\Payroll\Engine\PayslipVariables;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Payroll\Payslip\PayslipEntryCheck;
use App\Service\Payroll\Payslip\PayslipLineCells;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Money;
use App\Tests\Service\Payroll\Engine\StandardPlanFixture;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The payslip the student establishes at levels B, A and 0 (step 4.4c), on Thomas Lefèvre of maquette 4
 * (November 2026, 2 400,00, seniority 3 %): every open cell is to type, a typed cell is compared with the
 * tool's calculation without giving it, and typing the calculation's own values gives the same payslip.
 */
final class PayslipEntryCheckTest extends TestCase
{
    use StandardPlanFixture;

    public function testNothingTypedLeavesEveryCellMissing(): void
    {
        $reference = $this->payslip(PayslipAdjustments::none());
        $check = PayslipEntryCheck::of($reference, $reference, self::demoPlan());

        self::assertSame($this->openCells($reference), $check->missing());
        self::assertSame(0, $check->gaps());
        self::assertFalse($check->isComplete());
        self::assertFalse($check->isLineComplete('1000'));
        // The general reduction has no cell: its line is complete.
        self::assertTrue($check->isLineComplete('2900'));
        self::assertSame(PayslipEntryCheck::MISSING, $check->state('1000.base'));
    }

    public function testTypingTheCalculationGivesTheSamePayslip(): void
    {
        $reference = $this->payslip(PayslipAdjustments::none());
        $typed = $this->payslip(new PayslipAdjustments(forced: $this->everyCell($reference)));
        $check = PayslipEntryCheck::of($typed, $reference, self::demoPlan());

        self::assertSame(0, $check->missing());
        self::assertSame(0, $check->gaps());
        self::assertNull($check->validationRefusal(Assistance::at(AssistanceLevel::Blocking)));
        // A value forced to the calculation moves nothing: the mensualised salary stays 2 400,00, not 151,67 × 15,8238.
        self::assertSame([(string) $reference->gross->toDecimal(), (string) $reference->totals->netPay->toDecimal()], [(string) $typed->gross->toDecimal(), (string) $typed->totals->netPay->toDecimal()]);
        self::assertSame('2400.00', (string) $typed->gross->minus($typed->row('1010')->employeeAmount ?? Money::zero())->toDecimal());
    }

    public function testACellThatDiffersIsAGapNeverItsValue(): void
    {
        $reference = $this->payslip(PayslipAdjustments::none());
        $forced = $this->everyCell($reference);
        // The tranche 1 complementary pension at 4,50 % instead of 3,15 %.
        $forced['2300'] = new PayslipForcing(...[...$this->values($reference, '2300'), 'employeeRate' => BigDecimal::of('4.5')]);
        $typed = $this->payslip(new PayslipAdjustments(forced: $forced));
        $check = PayslipEntryCheck::of($typed, $reference, self::demoPlan());

        self::assertSame(['2300.employeeRate' => PayslipEntryCheck::GAP], $check->cells);
        self::assertSame('Une cellule diffère du calcul de l’outil : revoyez-la avant de valider.', $check->validationRefusal(Assistance::at(AssistanceLevel::Blocking)));
        // At A the gap is signalled and the payslip validates; at 0 no comparison is made.
        self::assertNull($check->validationRefusal(Assistance::at(AssistanceLevel::Warnings)));
        self::assertSame([], PayslipEntryCheck::of($typed, null, self::demoPlan())->cells);
    }

    public function testACellLeftEmptyStopsTheValidationAtEveryLevel(): void
    {
        $reference = $this->payslip(PayslipAdjustments::none());
        $forced = $this->everyCell($reference);
        unset($forced['2000']);
        $check = PayslipEntryCheck::of($this->payslip(new PayslipAdjustments(forced: $forced)), $reference, self::demoPlan());

        // 2000 carries its base and its employer's rate.
        self::assertSame(2, $check->missing());
        self::assertFalse($check->isLineComplete('2000'));
        foreach ([AssistanceLevel::Blocking, AssistanceLevel::Warnings, AssistanceLevel::None] as $level) {
            self::assertSame('2 cellules du bulletin restent à saisir.', $check->validationRefusal(Assistance::at($level)));
        }
    }

    private function payslip(PayslipAdjustments $adjustments): Payslip
    {
        $employee = new PayslipEmployee('Thomas Lefèvre', false, ContractType::Permanent, '151.67', Money::euros('2400.00'), BigDecimal::of('3.5'));

        return PayslipCalculator::calculate(new PayslipInput($employee, new PayslipVariables(rates: ['1010' => BigDecimal::of('3')]), self::regulatoryOn('2026-11-30'), self::demoPlan(), self::establishment(), PayslipYearToDate::none(), $adjustments));
    }

    private function openCells(Payslip $payslip): int
    {
        $specs = $this->specs();
        $count = 0;
        foreach ($payslip->rows as $row) {
            $count += \count(PayslipLineCells::open($row, $specs[$row->code] ?? null));
        }

        return $count;
    }

    /** @return array<array-key, PayslipForcing> every open cell forced to the calculation's value */
    private function everyCell(Payslip $payslip): array
    {
        $forced = [];
        foreach ($payslip->rows as $row) {
            $values = $this->values($payslip, $row->code);
            if ([] !== $values) {
                $forced[$row->code] = new PayslipForcing(...$values);
            }
        }

        return $forced;
    }

    /** @return array<string, BigDecimal|Money> the open cells of a line, as PayslipForcing takes them */
    private function values(Payslip $payslip, string $code): array
    {
        $row = $payslip->row($code) ?? throw new \LogicException('No line '.$code);
        $values = [];
        foreach (PayslipLineCells::open($row, $this->specs()[$code] ?? null) as $field) {
            $value = PayslipLineCells::value($row, $field) ?? throw new \LogicException('An open cell has a value.');
            $values[$field] = str_ends_with($field, 'Amount') ? Money::euros($value) : $value;
        }

        return $values;
    }

    /** @return array<array-key, PayrollItemSpec> */
    private function specs(): array
    {
        $specs = [];
        foreach (self::demoPlan() as $spec) {
            $specs[$spec->code] = $spec;
        }

        return $specs;
    }
}
