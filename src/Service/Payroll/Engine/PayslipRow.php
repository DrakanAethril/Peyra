<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * One line of a calculated payslip, one rubrique of the plan (lot 4, step 4.3): its base and rates
 * as printed, its amounts and how they were calculated. An element of gross pay has the employee's
 * side only, negative for what comes off (an absence); a contribution has an employee's and an
 * employer's share, each rounded to the cent on its own, negative for a reduction - and the line of
 * the clarified payslip it is summed under.
 */
final readonly class PayslipRow
{
    public function __construct(
        public string $code,
        public string $label,
        public PayrollItemKind $kind,
        public ?BigDecimal $base,
        public ?PayslipUnit $baseUnit,
        public ?BigDecimal $employeeRate,
        public ?PayslipUnit $employeeRateUnit,
        public Money $employeeAmount,
        public PayslipExplanation $explanation,
        public ?BigDecimal $employerRate = null,
        public ?PayslipUnit $employerRateUnit = null,
        public ?Money $employerAmount = null,
        public ?PayslipLine $line = null,
    ) {
    }
}
