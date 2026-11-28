<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollBodyKind;
use App\Enum\Payroll\PayrollItemKind;
use App\Service\Payroll\Plan\PayrollItemSpec;

/**
 * The accounts of the payroll's entry (step 4.5), pure: what a rubrique of the plan de paie is
 * charged or credited on when it names no account, and where a contribution is owed. The plan
 * comptable général's own accounts, as Sage's « ventilation comptable » sets them by default:
 *
 * - an element of gross pay on 641100 « Salaires, appointements », 641400 « Indemnités et avantages
 *   divers » for a benefit in kind; an item not subject on 625100 « Voyages et déplacements »;
 * - a deduction from the net credited to 442100 « Prélèvements à la source » (the tax), 649000
 *   « Remboursements de charges de personnel » (the benefits in kind taken back off the net, which the
 *   charges of the company already carry), else 425000 « Avances et acomptes » (an advance);
 * - a contribution's employer share charged on its body's charge account (645100 for the Urssaf…), and
 *   both shares owed on its body's account (431000, 437100…); a contribution with no body, the Urssaf's.
 *
 * The net to pay is owed on 421000 « Personnel - Rémunérations dues ».
 */
final class PayrollAccounts
{
    public const string NET_PAY = '421000';

    /** The account the rubrique's amount is charged on (gross, not subject, an employer's share) or credited to (a deduction). */
    public static function of(PayrollItemSpec $spec): string
    {
        return $spec->account ?? self::defaultOf($spec);
    }

    public static function defaultOf(PayrollItemSpec $spec): string
    {
        return match ($spec->kind) {
            PayrollItemKind::Gross => $spec->benefitInKind ? '641400' : '641100',
            PayrollItemKind::NotSubject => '625100',
            PayrollItemKind::Deduction => match ($spec->base) {
                PayrollBase::NetTaxable => '442100',
                PayrollBase::BenefitsInKind => '649000',
                default => '425000',
            },
            PayrollItemKind::Contribution => $spec->body?->employerChargeAccount() ?? PayrollBodyKind::Urssaf->defaultChargeAccount(),
        };
    }

    /** The account of a rubrique no longer in the plan: its family's default, the Urssaf's for a contribution. */
    public static function ofKind(PayrollItemKind $kind): string
    {
        return match ($kind) {
            PayrollItemKind::Gross => '641100',
            PayrollItemKind::NotSubject => '625100',
            PayrollItemKind::Deduction => '425000',
            PayrollItemKind::Contribution => PayrollBodyKind::Urssaf->defaultChargeAccount(),
        };
    }

    /** The account a contribution is owed on: its body's. */
    public static function owedOf(PayrollItemSpec $spec): string
    {
        return $spec->body?->owedAccount() ?? PayrollBodyKind::Urssaf->defaultAccount();
    }
}
