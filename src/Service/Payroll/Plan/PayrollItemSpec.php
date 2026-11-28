<?php

declare(strict_types=1);

namespace App\Service\Payroll\Plan;

use App\Entity\Payroll\PayrollBody;
use App\Entity\Payroll\PayrollRate;
use App\Enum\Payroll\HeadcountCondition;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollCeiling;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollStatusCondition;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\SalaryCondition;

/** An item of the plan de paie as PayrollItemRules accepted it: typed, ready to be written. */
final readonly class PayrollItemSpec
{
    public function __construct(
        public string $code,
        public string $label,
        public PayrollItemKind $kind,
        public int $sortOrder,
        public bool $subtracts,
        public PayrollBase $base,
        public PayrollCeiling $ceiling,
        public PayrollRate $employeeRate,
        public PayrollRate $employerRate,
        public PayrollStatusCondition $status,
        public HeadcountCondition $headcount,
        public SalaryCondition $salary,
        public ?PayrollBody $body,
        public ?PayslipLine $line,
        public bool $benefitInKind = false,
    ) {
    }
}
