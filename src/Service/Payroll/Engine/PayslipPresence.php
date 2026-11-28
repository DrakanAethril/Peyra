<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * What an absence does to the salary of the month (lot 4, step 4.3d), pure, read from the rubriques'
 * bases rather than their codes:
 *
 * - **the salary** is the contract's (base « horaire du contrat »);
 * - **an absence** takes some of it off: hours entered at the contract's hourly rate (unpaid leave,
 *   sickness), or the days of the month outside the contract. Together they never take more than the
 *   salary: the last one is cut, and says so;
 * - **the sick pay** gives some of it back.
 *
 * The SMIC of reference of the general reduction follows: corrected by the ratio of the salary due to the
 * salary that would have been due had the employee been there all month, on these elements alone - the
 * others (a bonus on the base salary, overtime, paid leave, paid in full) are not affected by the
 * absence (D241-7 IV CSS).
 */
final class PayslipPresence
{
    public static function isSalary(PayrollItemSpec $spec): bool
    {
        return PayrollItemKind::Gross === $spec->kind && PayrollBase::ContractHours === $spec->base;
    }

    public static function isAbsence(PayrollItemSpec $spec): bool
    {
        return PayrollItemKind::Gross === $spec->kind && $spec->subtracts && (PayrollBase::EntryExitDays === $spec->base
            || (PayrollBase::EnteredHours === $spec->base && PayrollRateSource::HourlyRate === $spec->employeeRate->getSource()));
    }

    public static function isSickPay(PayrollItemSpec $spec): bool
    {
        return PayrollItemKind::Gross === $spec->kind && PayrollBase::SickPay === $spec->base;
    }

    /**
     * The gross rows with the absences cut, in their order, so that the salary never goes below zero.
     *
     * @param list<PayrollItemSpec> $plan
     * @param list<PayslipRow>      $rows
     *
     * @return list<PayslipRow>
     */
    public static function capped(array $plan, array $rows): array
    {
        $specs = self::byCode($plan);
        $left = Money::zero();
        foreach ($rows as $row) {
            if (isset($specs[$row->code]) && self::isSalary($specs[$row->code])) {
                $left = $left->plus($row->employeeAmount);
            }
        }
        $capped = [];
        foreach ($rows as $row) {
            if (isset($specs[$row->code]) && self::isAbsence($specs[$row->code])) {
                $taken = $row->employeeAmount->negated();
                if ($taken->compareTo($left) > 0) {
                    $kept = $left->isNegative() ? Money::zero() : $left;
                    $row = new PayslipRow($row->code, $row->label, $row->kind, $row->base, $row->baseUnit, $row->employeeRate, $row->employeeRateUnit, $kept->negated(), new PayslipExplanation(
                        $row->explanation->formula.\sprintf(' ; limité à %s, le salaire restant du mois', FrenchFormat::amountInEuros($kept)),
                        $row->explanation->base,
                        $row->explanation->rate,
                        $row->explanation->source,
                        [...$row->explanation->notes, 'Les absences du mois ne retirent jamais plus que le salaire du contrat.'],
                    ), $row->employerRate, $row->employerRateUnit, $row->employerAmount, $row->line);
                    $taken = $kept;
                }
                $left = $left->minus($taken);
            }
            $capped[] = $row;
        }

        return $capped;
    }

    /**
     * The salary due and the salary that would have been due all month, on the elements an absence
     * affects; null when the month has no absence (no correction).
     *
     * @param list<PayrollItemSpec> $plan
     * @param list<PayslipRow>      $rows
     *
     * @return array{due: BigDecimal, full: BigDecimal, text: string}|null
     */
    public static function ratio(array $plan, array $rows): ?array
    {
        $specs = self::byCode($plan);
        $full = BigDecimal::zero();
        $changes = BigDecimal::zero();
        $codes = [];
        foreach ($rows as $row) {
            $spec = $specs[$row->code] ?? null;
            if (null === $spec) {
                continue;
            }
            if (self::isSalary($spec)) {
                $full = $full->plus($row->employeeAmount->toDecimal());
            } elseif (self::isAbsence($spec) || self::isSickPay($spec)) {
                $changes = $changes->plus($row->employeeAmount->toDecimal());
                $codes[] = $row->code;
            }
        }
        if ([] === $codes || !$full->isPositive()) {
            return null;
        }
        $due = BigDecimal::max(BigDecimal::zero(), BigDecimal::min($full, $full->plus($changes)));
        $euros = PayslipUnit::Euros;

        return ['due' => $due, 'full' => $full, 'text' => \sprintf('rapport du salaire dû au salaire du mois entier (rubriques %s) : %s ÷ %s', implode(', ', $codes), $euros->format($due), $euros->format($full))];
    }

    /**
     * @param list<PayrollItemSpec> $plan
     *
     * @return array<string, PayrollItemSpec>
     */
    private static function byCode(array $plan): array
    {
        $specs = [];
        foreach ($plan as $spec) {
            $specs[$spec->code] = $spec;
        }

        return $specs;
    }
}
