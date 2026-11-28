<?php

declare(strict_types=1);

namespace App\Service\Payroll\Settings;

/** The establishment's figures as PayrollEstablishmentRules accepted them: exact decimal texts. */
final readonly class PayrollEstablishmentFields
{
    public function __construct(
        public string $headcount,
        public string $workAccidentRate,
        public ?string $mobilityRate,
        public string $monthlyHours,
    ) {
    }
}
