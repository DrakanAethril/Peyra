<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

/**
 * One field of the « Éléments du mois » card (step 4.4a): a rubrique of the plan that takes a value
 * entered for the month - hours or a number (`quantity`), an amount, or a percent (`rate`) - with
 * the label and the unit the card prints. Its form name is `{kind}[{code}]`.
 */
final readonly class PayslipElementField
{
    public const string QUANTITY = 'quantity';
    public const string AMOUNT = 'amount';
    public const string RATE = 'rate';

    public function __construct(
        public string $code,
        public string $label,
        public string $kind,
        public string $unit,
    ) {
    }

    /** The key of the field among the posted values and the errors: « quantity.1100 ». */
    public function key(): string
    {
        return $this->kind.'.'.$this->code;
    }
}
