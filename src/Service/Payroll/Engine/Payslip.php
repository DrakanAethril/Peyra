<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;

/**
 * A calculated payslip (lot 4, step 4.3): its lines in the order of the plan de paie, and the gross
 * pay they add up to.
 */
final readonly class Payslip
{
    /** @param list<PayslipRow> $rows */
    public function __construct(
        public array $rows,
        public Money $gross,
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
}
