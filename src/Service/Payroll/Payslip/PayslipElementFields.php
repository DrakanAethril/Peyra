<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollRateSource;
use App\Service\Payroll\Plan\PayrollItemSpec;

/**
 * The fields of the « Éléments du mois » card (step 4.4a), read from the plan de paie: every
 * rubrique whose base or rate is entered for the month, in the plan's order - hours of overtime or
 * of absence, a number of meals, an amount of bonus or of advance, the seniority percent, the hours
 * of sick pay with the daily allowances received - plus the paid leave taken when the plan pays it.
 * A pure function of the plan; the card adds the whole days of absence without any pay. What only
 * the last payslip of a contract takes (leave not taken, severance, the gross of a fixed-term
 * contract) is not here: step 4.4c.
 */
final class PayslipElementFields
{
    /**
     * @param list<PayrollItemSpec> $plan
     *
     * @return list<PayslipElementField>
     */
    public static function of(array $plan): array
    {
        $fields = [];
        foreach ($plan as $spec) {
            switch ($spec->base) {
                case PayrollBase::EnteredHours:
                    $fields[] = new PayslipElementField($spec->code, $spec->label, PayslipElementField::QUANTITY, 'h');
                    break;
                case PayrollBase::EnteredCount:
                    $fields[] = new PayslipElementField($spec->code, $spec->label, PayslipElementField::QUANTITY, 'nombre');
                    break;
                case PayrollBase::EnteredAmount:
                    $fields[] = new PayslipElementField($spec->code, $spec->label, PayslipElementField::AMOUNT, '€');
                    break;
                case PayrollBase::SickPay:
                    $fields[] = new PayslipElementField($spec->code, $spec->label.' : heures maintenues', PayslipElementField::QUANTITY, 'h');
                    $fields[] = new PayslipElementField($spec->code, $spec->label.' : indemnités journalières reçues', PayslipElementField::AMOUNT, '€');
                    break;
                default:
                    break;
            }
            if (PayrollRateSource::Entered === $spec->employeeRate->getSource()) {
                $fields[] = new PayslipElementField($spec->code, $spec->label, PayslipElementField::RATE, '%');
            }
        }

        return $fields;
    }

    /** @param list<PayrollItemSpec> $plan */
    public static function takesPaidLeave(array $plan): bool
    {
        foreach ($plan as $spec) {
            if (\in_array($spec->base, [PayrollBase::PaidLeaveHours, PayrollBase::PaidLeavePay], true)) {
                return true;
            }
        }

        return false;
    }
}
