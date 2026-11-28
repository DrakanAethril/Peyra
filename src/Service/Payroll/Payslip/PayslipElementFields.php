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
 * A pure function of the plan; the card adds the whole days of absence without any pay. On the last
 * payslip of a fixed-term contract at its term (step 4.4c, LastPayslipEntries) the end-of-contract
 * indemnity's rubrique takes the contract's gross pay of the months before; the leave not taken and the
 * severance are the card's own groups of fields.
 */
final class PayslipElementFields
{
    /**
     * @param list<PayrollItemSpec> $plan
     *
     * @return list<PayslipElementField>
     */
    public static function of(array $plan, ?LastPayslipEntries $last = null): array
    {
        $fields = [];
        foreach ($plan as $spec) {
            switch ($spec->base) {
                case PayrollBase::FixedTermEnd:
                    if (true === $last?->fixedTermGross) {
                        $fields[] = new PayslipElementField($spec->code, $spec->label.' : brut du contrat des mois précédents', PayslipElementField::AMOUNT, '€');
                    }
                    break;
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
