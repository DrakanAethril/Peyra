<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Enum\Payroll\PayrollBodyKind;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Engine\GeneralReduction;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Shared\Money;

/**
 * The lines of the month's validated payslips put where they are paid and posted (step 4.5), pure:
 * one allocation per line of each payslip, with the rubrique's body and accounts read on the plan
 * (PayrollChart, PayrollAccounts) - a rubrique no longer in the plan takes its family's default.
 *
 * The general reduction is one line of the payslip but two debts lessened (lot 4, step 4.3b: « son
 * imputation ») : its Urssaf share comes off the Urssaf, its complementary pension share off the
 * pension body - when the dossier has one, else the whole of it off the line's body.
 */
final class PayrollAllocations
{
    /**
     * @param list<PayrollMonthPayslip> $payslips
     *
     * @return list<PayrollAllocation>
     */
    public static function of(array $payslips, PayrollChart $chart): array
    {
        $allocations = [];
        foreach ($payslips as $payslip) {
            foreach ($payslip->payslip->rows as $row) {
                array_push($allocations, ...self::row($payslip->number, $row, $payslip->payslip->generalReduction, $chart));
            }
        }

        return $allocations;
    }

    /** @return list<PayrollAllocation> */
    private static function row(string $number, PayslipRow $row, ?GeneralReduction $reduction, PayrollChart $chart): array
    {
        $spec = $chart->item($row->code);
        $account = null === $spec ? PayrollAccounts::ofKind($row->kind) : PayrollAccounts::of($spec);
        if (PayrollItemKind::Contribution !== $row->kind) {
            return [new PayrollAllocation($number, $row->code, $row->label, $row->kind, $row->line, $row->base, $row->baseUnit, $row->employeeAmount, Money::zero(), null, $account, '')];
        }
        $body = $spec?->body;
        $owed = null === $spec ? PayrollBodyKind::Urssaf->defaultAccount() : PayrollAccounts::owedOf($spec);
        $employer = $row->employerAmount ?? Money::zero();
        $retirement = $chart->retirement;
        if (null !== $reduction && null !== $retirement && PayslipUnit::Coefficient === $row->employerRateUnit
            && $employer->equals($reduction->amount->negated()) && !$reduction->retirementShare->isZero()) {
            return [
                new PayrollAllocation($number, $row->code, $row->label, $row->kind, $row->line, $row->base, $row->baseUnit, $row->employeeAmount, $reduction->urssafShare->negated(), $body, $account, $owed),
                new PayrollAllocation($number, $row->code, $row->label.' (part retraite complémentaire)', $row->kind, $row->line, null, null, Money::zero(), $reduction->retirementShare->negated(), $retirement, $retirement->employerChargeAccount(), $retirement->owedAccount()),
            ];
        }

        return [new PayrollAllocation($number, $row->code, $row->label, $row->kind, $row->line, $row->base, $row->baseUnit, $row->employeeAmount, $employer, $body, $account, $owed)];
    }
}
