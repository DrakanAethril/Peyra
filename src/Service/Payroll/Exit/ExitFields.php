<?php

declare(strict_types=1);

namespace App\Service\Payroll\Exit;

use App\Enum\Payroll\ExitReason;

/** An exit ExitRules accepted: what EmployeeExits writes. */
final readonly class ExitFields
{
    public function __construct(
        public ExitReason $reason,
        public ?\DateTimeImmutable $notifiedOn,
        public \DateTimeImmutable $exitOn,
    ) {
    }
}
