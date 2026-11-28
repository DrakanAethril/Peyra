<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

/**
 * How a payslip line was calculated (lot 4, step 4.3), in French for the student: the operation
 * with its figures, where the base comes from, where the rate comes from (with the regulatory
 * value read, if any), and what else decided the amount - the better of two calculations.
 */
final readonly class PayslipExplanation
{
    /** @param list<string> $notes */
    public function __construct(
        public string $formula,
        public string $base,
        public string $rate,
        public ?PayslipSource $source = null,
        public array $notes = [],
    ) {
    }
}
