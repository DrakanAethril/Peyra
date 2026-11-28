<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\ExitReason;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Payroll\Engine\Payslip;

/**
 * An employee of the DSN's preparatory state (step 4.6): the individual (identity, NIR as printed),
 * the contract in force on the pay day, the exit when it falls in the month, and the validated payslip
 * every amount of the remuneration comes from (frozen, never calculated again).
 */
final readonly class DsnIndividual
{
    public function __construct(
        public string $number,
        public string $lastName,
        public string $firstName,
        public Sex $sex,
        public \DateTimeImmutable $birthDate,
        public ?string $birthPlace,
        public ?string $nir,
        public ContractType $contract,
        public \DateTimeImmutable $hiredOn,
        public ?\DateTimeImmutable $contractEndsOn,
        public string $jobTitle,
        public bool $executive,
        public WorkingTime $workingTime,
        public string $monthlyHours,
        public ?\DateTimeImmutable $exitOn,
        public ?ExitReason $exitReason,
        public Payslip $payslip,
    ) {
    }
}
