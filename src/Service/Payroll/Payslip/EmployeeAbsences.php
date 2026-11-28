<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\PayrollBase;
use App\Service\Payroll\Engine\PayslipPresence;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;

/**
 * The absences of an employee month by month (the fiche's « Absences » tab, step 4.4c), pure, read on
 * the elements entered on their payslips: the paid leave taken, the hours of each absence rubrique and
 * of sick pay - recognised by their base, never their code (PayslipPresence) - and the whole days
 * without any pay. A month with none of them is left out.
 */
final class EmployeeAbsences
{
    /**
     * @param array<string, PayslipElements> $months by first day of the month, « Y-m-d »
     * @param list<PayrollItemSpec>          $plan
     *
     * @return list<array{month: \DateTimeImmutable, paidLeave: ?string, absences: list<string>, sickHours: ?BigDecimal, unpaidDays: int}>
     */
    public static function of(array $months, array $plan): array
    {
        $rows = [];
        foreach ($months as $day => $elements) {
            $absences = [];
            $sick = null;
            foreach ($plan as $spec) {
                $hours = $elements->quantities[$spec->code] ?? null;
                if (null === $hours || $hours->isZero()) {
                    continue;
                }
                if (PayrollBase::SickPay === $spec->base) {
                    $sick = $hours;
                } elseif (PayslipPresence::isAbsence($spec)) {
                    $absences[] = \sprintf('%s : %s h', $spec->label, FrenchFormat::number($hours));
                }
            }
            $leave = $elements->paidLeave;
            if ([] === $absences && null === $sick && null === $leave && 0 === $elements->unpaidDays) {
                continue;
            }
            $rows[] = [
                'month' => new \DateTimeImmutable($day),
                'paidLeave' => null === $leave ? null : \sprintf('%s j (%s h)', FrenchFormat::number($leave->daysTaken), FrenchFormat::number($leave->hours)),
                'absences' => $absences,
                'sickHours' => $sick,
                'unpaidDays' => $elements->unpaidDays,
            ];
        }

        return $rows;
    }
}
