<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Regulatory\RegulatorySnapshot;

/**
 * Everything one payslip is calculated from (lot 4, step 4.3), and nothing else: the employee on
 * the day, the elements of the month, the regulatory values in force on the payslip's day, the
 * dossier's plan de paie and its establishment, and the employee's cumulative figures of the calendar
 * year before this payslip (the progressive regularisation, step 4.3c), and what was changed by hand on it (step 4.4b). The engine reads neither the database nor the clock.
 */
final readonly class PayslipInput
{
    /** @param list<PayrollItemSpec> $plan */
    public function __construct(
        public PayslipEmployee $employee,
        public PayslipVariables $variables,
        public RegulatorySnapshot $regulatory,
        public array $plan,
        public PayslipEstablishment $establishment,
        public PayslipYearToDate $yearToDate = new PayslipYearToDate(),
        public PayslipAdjustments $adjustments = new PayslipAdjustments(),
    ) {
    }
}
