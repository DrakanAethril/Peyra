<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The provision for paid leave at a day (step 4.5, for the inventory of lot 3), pure - a simple
 * reading, explained line by line:
 *
 * - the leave acquired since the reference period began (1st June by default, code du travail
 *   R3141-4: `social.paid_leave.reference_period`) is taken from the next period on, so at the day it
 *   is still owed; its pay is the tenth of the gross pay of that period (`social.paid_leave.pay`), read
 *   on the validated payslips of its months up to the day;
 * - the employer's contributions on it, at the employee's own average rate over the same payslips
 *   (employer's contributions ÷ gross pay, reductions included, in percent to two decimals).
 *
 * The leave of the period before, not yet taken at the day, is left out: Peyra keeps no count of the
 * days taken (lot 4), so it is held taken or paid. The entry (641200 / 428200, 645… / 438200) is the
 * inventory's.
 */
final class PaidLeaveProvision
{
    /**
     * @param list<array{number: string, name: string, gross: Money, employer: Money, months: int}> $employees what each one's validated payslips of the period sum to
     * @param BigDecimal                                                                         $tenth     the percent of the gross pay, 10
     *
     * @return array{lines: list<array{number: string, name: string, gross: Money, months: int, provision: Money, rate: BigDecimal, charges: Money, explanation: string}>, provision: Money, charges: Money} `rate` in percent, two decimals
     */
    public static function at(array $employees, BigDecimal $tenth): array
    {
        $lines = [];
        foreach ($employees as $employee) {
            if (!$employee['gross']->isPositive()) {
                continue;
            }
            $provision = $employee['gross']->times($tenth->dividedBy(100, 6, RoundingMode::Unnecessary), RoundingMode::HalfUp);
            $rate = $employee['employer']->toDecimal()->multipliedBy(100)->dividedBy($employee['gross']->toDecimal(), 2, RoundingMode::HalfUp);
            $charges = $provision->times($rate->dividedBy(100, 4, RoundingMode::Unnecessary), RoundingMode::HalfUp);
            $lines[] = [
                'number' => $employee['number'],
                'name' => $employee['name'],
                'gross' => $employee['gross'],
                'months' => $employee['months'],
                'provision' => $provision,
                'rate' => $rate,
                'charges' => $charges,
                'explanation' => \sprintf(
                    'Brut de %d bulletin%s de la période : %s. Congés acquis non pris : %s %% × %s = %s. Taux moyen des charges patronales : %s ÷ %s = %s %%. Charges : %s %% × %s = %s.',
                    $employee['months'],
                    $employee['months'] > 1 ? 's' : '',
                    FrenchFormat::amountInEuros($employee['gross']),
                    FrenchFormat::number($tenth),
                    FrenchFormat::amountInEuros($employee['gross']),
                    FrenchFormat::amountInEuros($provision),
                    FrenchFormat::amountInEuros($employee['employer']),
                    FrenchFormat::amountInEuros($employee['gross']),
                    FrenchFormat::number($rate),
                    FrenchFormat::number($rate),
                    FrenchFormat::amountInEuros($provision),
                    FrenchFormat::amountInEuros($charges),
                ),
            ];
        }

        return [
            'lines' => $lines,
            'provision' => Money::sum(array_map(static fn (array $line): Money => $line['provision'], $lines)),
            'charges' => Money::sum(array_map(static fn (array $line): Money => $line['charges'], $lines)),
        ];
    }

    /** The first day of the reference period the day falls in: the start's day and month this year, or last year's if still ahead. */
    public static function periodStart(\DateTimeImmutable $day, int $startMonth, int $startDay): \DateTimeImmutable
    {
        $start = $day->setDate((int) $day->format('Y'), $startMonth, $startDay)->setTime(0, 0);

        return $start > $day ? $start->modify('-1 year') : $start;
    }
}
