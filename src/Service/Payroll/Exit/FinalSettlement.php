<?php

declare(strict_types=1);

namespace App\Service\Payroll\Exit;

use App\Enum\Payroll\PayrollBase;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\Money;

/**
 * The sums of the reçu pour solde de tout compte (L1234-20 of the code du travail; step 4.4c), pure,
 * read on the last payslip of the contract as validated: the gross salary of the last month without the
 * end-of-contract indemnities, then each of them by its rubrique - recognised by its base, never its
 * code: the compensatory paid leave, the end of a fixed-term contract (gross pay), the dismissal or
 * conventional termination indemnity (paid outside the gross) - and the net paid on that payslip,
 * which is the sum the employee receives in settlement.
 */
final readonly class FinalSettlement
{
    /** @param list<array{label: string, amount: Money, gross: bool}> $indemnities */
    private function __construct(
        public Money $lastMonthSalary,
        public array $indemnities,
        public Money $netPaid,
    ) {
    }

    /** @param list<PayrollItemSpec> $plan */
    public static function of(Payslip $payslip, array $plan): self
    {
        $bases = [];
        foreach ($plan as $spec) {
            $bases[$spec->code] = $spec->base;
        }
        $indemnities = [];
        $salary = $payslip->gross;
        foreach ($payslip->rows as $row) {
            $base = $bases[$row->code] ?? null;
            if (!\in_array($base, [PayrollBase::LeaveCompensation, PayrollBase::FixedTermEnd, PayrollBase::Dismissal, PayrollBase::ConventionalBreak], true) || $row->employeeAmount->isZero()) {
                continue;
            }
            $gross = PayrollBase::LeaveCompensation === $base || PayrollBase::FixedTermEnd === $base;
            if ($gross) {
                $salary = $salary->minus($row->employeeAmount);
            }
            $indemnities[] = ['label' => $row->label, 'amount' => $row->employeeAmount, 'gross' => $gross];
        }

        return new self($salary, $indemnities, $payslip->totals->netPay);
    }
}
