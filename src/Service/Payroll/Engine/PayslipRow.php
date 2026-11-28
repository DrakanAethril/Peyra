<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * One line of a calculated payslip, one rubrique of the plan (lot 4, step 4.3): its base and rate
 * as printed, its amount - negative for what comes off (an absence) - and how it was calculated.
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
    ) {
    }
}
