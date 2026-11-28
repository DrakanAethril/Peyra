<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Entity\Payroll\PayrollBody;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * A line of a validated payslip put where the payroll's states and entry read it (step 4.5): the
 * payslip it comes from (`number`), its rubrique, its amounts, the body a contribution is paid to and
 * its two accounts - `account` charged (gross, not subject, an employer's share) or credited (a
 * deduction), `owed` the body's account a contribution's shares are owed on. The general reduction is
 * two of them, its Urssaf share and its complementary pension share (PayrollAllocations).
 */
final readonly class PayrollAllocation
{
    public function __construct(
        public string $number,
        public string $code,
        public string $label,
        public PayrollItemKind $kind,
        public ?PayslipLine $line,
        public ?BigDecimal $base,
        public ?PayslipUnit $baseUnit,
        public Money $employee,
        public Money $employer,
        public ?PayrollBody $body,
        public string $account,
        public string $owed,
    ) {
    }

    /** The body's key in the states: its id, or the object's own for a body not yet stored (the tests). */
    public static function bodyKey(PayrollBody $body): string
    {
        return null === $body->getId() ? 'o'.spl_object_id($body) : (string) $body->getId();
    }
}
