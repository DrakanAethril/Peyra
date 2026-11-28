<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipSection;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The payslip as the clarified model prints it (step 4.4b; arrêté du 25 février 2016, design-ui.md
 * § 4.18): the elements of gross pay one by one, then the contributions **grouped by line of the
 * clarified model** under its sections - a line's shares are the sum of its rubriques', each rounded
 * on its own (DECISIONS 4.3b); its base is printed when its rubriques share one, its rate when they
 * share a base and are all percents (then the sum of their rates) -, then the items not subject and
 * the deductions from the net but the withholding tax, which the foot of the page prints.
 *
 * @phpstan-type PrintLine array{label: string, base: ?BigDecimal, baseUnit: ?PayslipUnit, employeeRate: ?BigDecimal, employeeRateUnit: ?PayslipUnit, employeeAmount: ?Money, employerRate: ?BigDecimal, employerRateUnit: ?PayslipUnit, employerAmount: ?Money}
 * @phpstan-type PrintBlock array{heading: string, lines: list<PrintLine>}
 */
final class PayslipPrintLines
{
    /** @return array{gross: list<PrintLine>, contributions: list<PrintBlock>, after: list<PrintBlock>} */
    public static function of(Payslip $payslip): array
    {
        $gross = [];
        $groups = [];
        $loose = [];
        $notSubject = [];
        $deductions = [];
        foreach ($payslip->rows as $row) {
            if (PayrollItemKind::Gross === $row->kind) {
                $gross[] = self::single($row);
            } elseif (PayrollItemKind::Contribution === $row->kind) {
                if (null === $row->line) {
                    $loose[] = self::single($row);
                } else {
                    $groups[$row->line->section()->value][$row->line->value][] = $row;
                }
            } elseif (PayrollItemKind::NotSubject === $row->kind) {
                $notSubject[] = self::single($row);
            } elseif (null === $row->employeeRate) {
                // Among the deductions only the withholding tax has a rate (its base is the net taxable): the foot prints it.
                $deductions[] = self::single($row);
            }
        }
        $contributions = [];
        foreach (PayslipSection::cases() as $section) {
            if (!isset($groups[$section->value])) {
                continue;
            }
            $lines = [];
            foreach ($groups[$section->value] as $rows) {
                $lines[] = self::grouped($rows);
            }
            $contributions[] = ['heading' => $section->label(), 'lines' => $lines];
        }
        if ([] !== $loose) {
            $contributions[] = ['heading' => 'Autres cotisations', 'lines' => $loose];
        }
        $after = [];
        if ([] !== $notSubject) {
            $after[] = ['heading' => PayrollItemKind::NotSubject->heading(), 'lines' => $notSubject];
        }
        if ([] !== $deductions) {
            $after[] = ['heading' => PayrollItemKind::Deduction->heading(), 'lines' => $deductions];
        }

        return ['gross' => $gross, 'contributions' => $contributions, 'after' => $after];
    }

    /** @return PrintLine */
    private static function single(PayslipRow $row): array
    {
        return [
            'label' => $row->label,
            'base' => $row->base,
            'baseUnit' => $row->baseUnit,
            'employeeRate' => $row->employeeRate,
            'employeeRateUnit' => $row->employeeRateUnit,
            'employeeAmount' => $row->employeeAmount->isZero() && null !== $row->employerAmount ? null : $row->employeeAmount,
            'employerRate' => $row->employerRate,
            'employerRateUnit' => $row->employerRateUnit,
            'employerAmount' => $row->employerAmount,
        ];
    }

    /**
     * @param non-empty-list<PayslipRow> $rows the rubriques of one line of the clarified model
     *
     * @return PrintLine
     */
    private static function grouped(array $rows): array
    {
        $line = $rows[0]->line ?? throw new \LogicException('A grouped line has its line.');
        $base = $rows[0]->base;
        $unit = $rows[0]->baseUnit;
        foreach ($rows as $row) {
            if (null === $base || null === $row->base || !$row->base->isEqualTo($base) || $row->baseUnit !== $unit) {
                $base = null;
                break;
            }
        }
        $employee = Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employeeAmount, $rows));
        $employerRows = array_filter($rows, static fn (PayslipRow $row): bool => null !== $row->employerAmount);
        $employer = [] === $employerRows ? null : Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employerAmount ?? Money::zero(), $employerRows));

        $employeeRate = null === $base ? null : self::summedRate($rows, true);
        $employerRate = null === $base ? null : self::summedRate($rows, false);

        return [
            'label' => $line->label(),
            'base' => $base,
            'baseUnit' => null === $base ? null : $unit,
            'employeeRate' => $employeeRate,
            'employeeRateUnit' => null === $employeeRate ? null : PayslipUnit::Percent,
            'employeeAmount' => $employee->isZero() && null !== $employer ? null : $employee,
            'employerRate' => $employerRate,
            'employerRateUnit' => null === $employerRate ? null : PayslipUnit::Percent,
            'employerAmount' => $employer,
        ];
    }

    /**
     * The sum of the rubriques' rates on one side, when every rate there is a percent; null when a side has none or another unit.
     *
     * @param list<PayslipRow> $rows
     */
    private static function summedRate(array $rows, bool $employee): ?BigDecimal
    {
        $sum = null;
        foreach ($rows as $row) {
            $rate = $employee ? $row->employeeRate : $row->employerRate;
            $unit = $employee ? $row->employeeRateUnit : $row->employerRateUnit;
            if (null === $rate) {
                continue;
            }
            if (PayslipUnit::Percent !== $unit) {
                return null;
            }
            $sum = null === $sum ? $rate : $sum->plus($rate);
        }

        return $sum;
    }
}
