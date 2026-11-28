<?php

declare(strict_types=1);

namespace App\Service\Payroll\Hiring;

use App\Entity\Payroll\Employee;
use App\Enum\Payroll\WorkingTime;

/**
 * The registre unique du personnel (articles L1221-13 and D1221-23 du code du travail; step 4.2b),
 * read from the employees, never typed: every person ever hired by the establishment, in the order
 * of hiring - an order number, name and first names, nationality, birth date, sex, job and
 * qualification at hire, days of entry and of departure, the kind of contract (fixed-term,
 * apprenticeship, part time). A departure day arrives with step 4.2c.
 *
 * @phpstan-type RegisterRow array{rank: int, employee: Employee, job: string, qualification: string, contract: string}
 */
final class PersonnelRegister
{
    /**
     * @param list<Employee> $employees
     *
     * @return list<RegisterRow>
     */
    public static function rows(array $employees): array
    {
        usort($employees, static fn (Employee $a, Employee $b): int => [$a->getHiredOn(), $a->getRegistrationNumber()] <=> [$b->getHiredOn(), $b->getRegistrationNumber()]);
        $rows = [];
        foreach ($employees as $index => $employee) {
            $first = $employee->getTerms()[0] ?? throw new \LogicException(\sprintf('Employee %s has no terms.', $employee->getRegistrationNumber()));
            $contract = $employee->getContractType()->label();
            if (WorkingTime::PartTime === $first->getWorkingTime()) {
                $contract .= ', temps partiel';
            }
            $rows[] = [
                'rank' => $index + 1,
                'employee' => $employee,
                'job' => $first->getJobTitle(),
                'qualification' => '' === $first->getClassification() ? $first->getStatusLabel() : $first->getClassification().' ('.mb_strtolower($first->getStatusLabel()).')',
                'contract' => $contract,
            ];
        }

        return $rows;
    }
}
