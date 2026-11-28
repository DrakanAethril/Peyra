<?php

declare(strict_types=1);

namespace App\Service\Payroll\Employee;

use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\Sex;
use App\Enum\Payroll\WorkingTime;
use App\Service\Shared\Money;

/** An employee once EmployeeRules accepted them: typed, normalised, what EmployeeSheet writes. */
final readonly class EmployeeFields
{
    public function __construct(
        public string $registrationNumber,
        public string $lastName,
        public string $firstName,
        public Sex $sex,
        public \DateTimeImmutable $birthDate,
        public ?string $birthPlace,
        public string $nationality,
        public ?string $nir,
        public ?string $street,
        public ?string $postalCode,
        public ?string $city,
        public ?string $withholdingRate,
        public ContractType $contractType,
        public \DateTimeImmutable $hiredOn,
        public ?\DateTimeImmutable $contractEndsOn,
        public ?string $fixedTermReason,
        public ?string $diploma,
        public ?string $trainingCentre,
        public \DateTimeImmutable $termsFrom,
        public string $jobTitle,
        public string $classification,
        public bool $executive,
        public WorkingTime $workingTime,
        public string $monthlyHours,
        public Money $baseSalary,
    ) {
    }
}
