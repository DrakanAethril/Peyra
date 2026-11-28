<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The general reduction of a payslip (lot 4, step 4.3b): the formula of the millésime that made it
 * (« rgcp » in 2025, « rgdu » from 2026), the monthly remuneration and the SMIC of reference it
 * compared, its coefficient and amount, and the amount's imputation - on the Urssaf, by the share of
 * the Urssaf's rates in the coefficient's maximum, rounded to the cent, and the rest on the
 * Agirc-Arrco complementary pension (FAQ Agirc-Arrco, « Réduction générale des cotisations », 2026).
 * Regularised over the year (step 4.3c), the month's amount is the year's less what was already made -
 * negative when the year's reduction falls - and the year's figures are kept beside it.
 */
final readonly class GeneralReduction
{
    /** @param list<string> $steps the calculation, figure by figure, in French */
    public function __construct(
        public string $regime,
        public Money $remuneration,
        public Money $smic,
        public BigDecimal $coefficient,
        public Money $amount,
        public Money $urssafShare,
        public Money $retirementShare,
        public array $steps,
        public PayslipSource $source,
        public Money $yearRemuneration,
        public Money $yearSmic,
        public Money $yearAmount,
    ) {
    }
}
