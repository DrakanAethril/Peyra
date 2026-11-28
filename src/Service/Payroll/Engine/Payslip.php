<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayslipLine;
use App\Service\Shared\Money;

/**
 * A calculated payslip (lot 4, step 4.3): its lines in the order of the plan de paie, the gross pay
 * they add up to, and the general reduction with its imputation when there is one.
 *
 * A line of the clarified payslip is the sum of its rubriques' amounts, each already rounded to the
 * cent (the rounding rule, DECISIONS 4.3b): never its base times a summed rate rounded once.
 */
final readonly class Payslip
{
    /** @param list<PayslipRow> $rows */
    public function __construct(
        public array $rows,
        public Money $gross,
        public ?GeneralReduction $generalReduction = null,
    ) {
    }

    public function row(string $code): ?PayslipRow
    {
        foreach ($this->rows as $row) {
            if ($row->code === $code) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{employee: Money, employer: Money} the shares of the rubriques summed under that line of the clarified payslip */
    public function lineTotal(PayslipLine $line): array
    {
        $rows = array_filter($this->rows, static fn (PayslipRow $row): bool => $row->line === $line);

        return [
            'employee' => Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employeeAmount, $rows)),
            'employer' => Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employerAmount ?? Money::zero(), $rows)),
        ];
    }
}
