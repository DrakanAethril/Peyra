<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Service\Payroll\Engine\Payslip;

/** A validated payslip of the month as the payroll's states read it (step 4.5): whose it is and its frozen figures. */
final readonly class PayrollMonthPayslip
{
    public function __construct(
        public string $number,
        public string $name,
        public Payslip $payslip,
    ) {
    }
}
