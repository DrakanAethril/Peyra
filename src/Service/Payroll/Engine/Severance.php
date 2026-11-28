<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;

/**
 * What the last payslip of a dismissal or a conventional termination needs beyond the fiche (lot 4,
 * step 4.3e), entered with the elements of the month: the reference salary of the legal indemnity (the
 * better of the monthly averages of the last twelve and the last three months, R1234-4 of the code du
 * travail - the caller's or the student's to work out), the indemnity agreed in a conventional
 * termination (null: the legal amount), and the gross pay of the calendar year before the termination,
 * which the income tax exemption of an indemnity above the legal amount reads (CGI art. 80 duodecies).
 */
final readonly class Severance
{
    public function __construct(
        public Money $referenceSalary,
        public ?Money $agreed = null,
        public ?Money $previousYearGross = null,
    ) {
        if (!$referenceSalary->isPositive() || true === $agreed?->isNegative() || true === $previousYearGross?->isNegative()) {
            throw new \InvalidArgumentException('A severance indemnity stands on a positive reference salary.');
        }
    }
}
