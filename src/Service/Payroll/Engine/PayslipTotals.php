<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The figures under the lines of a payslip (lot 4, step 4.3c), as the clarified payslip prints them,
 * each with how it was calculated (`explanations`, by the property's name): the contributions summed,
 * the net social amount, the net taxable and the overtime exempt from it, the net to pay before tax,
 * the withholding tax (its rate, sent by the tax office or neutral) and the net paid, the employer's cost.
 */
final readonly class PayslipTotals
{
    /** @param array<string, PayslipExplanation> $explanations */
    public function __construct(
        public Money $employeeContributions,
        public Money $employerContributions,
        public Money $netSocial,
        public Money $netTaxable,
        public Money $exemptOvertime,
        public Money $netBeforeTax,
        public BigDecimal $withholdingRate,
        public bool $neutralRate,
        public Money $withholdingTax,
        public Money $netPay,
        public Money $employerCost,
        public array $explanations,
    ) {
    }
}
