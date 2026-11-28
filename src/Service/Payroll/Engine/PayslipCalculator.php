<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\ExitReason;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The payslip engine (lot 4, step 4.3): a pure function from a PayslipInput to a Payslip - no
 * database, no clock, no rate written here. Each rubrique of the plan de paie is read for what it
 * says (its base, the source of its rate, its conditions) and becomes a line that explains itself.
 *
 * Step 4.3a calculates the gross pay, step 4.3b the contributions (PayslipContributions), step 4.3c
 * the items not subject (read as an element of gross pay is), then the deductions, the net figures and
 * the year to date (PayslipNet). The gross pay:
 * - the salary of the contract, mensualised: its monthly hours at the hourly rate, the amount being
 *   the salary itself (an hourly rate rounded to four decimals would miss it by a few cents);
 * - hours entered (overtime, complementary hours, an absence) at the hourly rate, raised by the
 *   premium the rubrique names; an absence is taken off at the hourly rate of the contract (the
 *   method retained, DECISIONS 4.3a);
 * - a percent of the base salary (the seniority bonus, its rate entered in the month);
 * - a number at a price (meals provided, valued at the regulatory amount);
 * - an amount entered (a bonus, a company car);
 * - the paid leave: its hours off the salary, and its pay, the better of the salary kept and the
 *   tenth of the reference period's gross pay for the days taken (L3141-24);
 * - step 4.3d: the days of the month outside the contract (hired or leaving during the month), the
 *   sick pay (the salary kept less the daily allowances), and the absences cut so that they never take
 *   more than the salary (PayslipPresence). The month's ceiling and SMIC of reference follow
 *   (PayslipMonth, PayslipPresence::ratio()).
 * - step 4.3e: the end of a contract, once the month's gross pay is known - the end-of-contract indemnity
 *   of a fixed-term contract reaching its term (the contract's gross pay times its rate), then the
 *   compensatory paid leave indemnity (the paid leave's rule on the days left, its tenth on a reference
 *   that includes the month and that indemnity); among the items not subject, the dismissal or conventional
 *   termination indemnity (PayslipSeverance).
 * - step 4.4b: what was changed by hand (PayslipAdjustments) - a rubrique removed is not read, one
 *   added is read whatever its condition of status, a value forced replaces the calculated one and what
 *   follows it is calculated from it.
 */
final class PayslipCalculator
{
    public static function calculate(PayslipInput $input): Payslip
    {
        $ordered = self::ordered($input->plan);
        $rows = [];
        $closing = [];
        $adjustments = $input->adjustments;
        $taken = [];
        foreach ($ordered as $spec) {
            if (PayrollItemKind::Gross !== $spec->kind || !$adjustments->takes($spec, $input->employee->executive)) {
                continue;
            }
            if (PayrollBase::FixedTermEnd === $spec->base || PayrollBase::LeaveCompensation === $spec->base) {
                $closing[] = $spec;
                continue;
            }
            $taken[] = $spec;
            $row = self::grossRow($spec, $input);
            if (null !== $row) {
                $rows[] = $row;
            }
        }
        $rows = self::adjusted($taken, PayslipPresence::capped($input->plan, $rows), $adjustments);
        // The end of the contract reads the month's gross pay: the fixed-term indemnity first, the
        // compensatory leave last (its tenth counts that indemnity).
        foreach ([PayrollBase::FixedTermEnd, PayrollBase::LeaveCompensation] as $base) {
            foreach ($closing as $spec) {
                if ($base !== $spec->base) {
                    continue;
                }
                $soFar = Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employeeAmount, $rows));
                $row = $adjustments->apply($spec, PayrollBase::FixedTermEnd === $base ? self::fixedTermRow($spec, $input, $soFar) : self::compensatoryLeaveRow($spec, $input, $soFar));
                if (null !== $row) {
                    $rows[] = $row;
                }
            }
        }
        $rows = self::inPlanOrder($ordered, $rows);
        $gross = Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employeeAmount, $rows));
        $contributions = PayslipContributions::calculate($input, $ordered, $rows, $gross);
        $notSubject = [];
        foreach ($ordered as $spec) {
            if (PayrollItemKind::NotSubject === $spec->kind && $adjustments->takes($spec, $input->employee->executive)) {
                $row = $adjustments->apply($spec, self::grossRow($spec, $input));
                if (null !== $row) {
                    $notSubject[] = $row;
                }
            }
        }
        $lines = [...$rows, ...$contributions['rows'], ...$notSubject];
        $net = PayslipNet::calculate($input, $ordered, $lines, $gross, $contributions['overtimePay'], $contributions['reduction'], $contributions['smic'], $contributions['ceiling']);

        return new Payslip([...$lines, ...$net['rows']], $gross, $contributions['reduction'], $net['totals'], $net['yearToDate']);
    }

    /**
     * @param list<PayrollItemSpec> $plan
     *
     * @return list<PayrollItemSpec> by family, then by their place in it
     */
    private static function ordered(array $plan): array
    {
        $families = array_flip(array_map(static fn (PayrollItemKind $kind): string => $kind->value, PayrollItemKind::cases()));
        usort($plan, static fn (PayrollItemSpec $a, PayrollItemSpec $b): int => [$families[$a->kind->value], $a->sortOrder, $a->code] <=> [$families[$b->kind->value], $b->sortOrder, $b->code]);

        return $plan;
    }

    /**
     * The gross rows once adjusted by hand (step 4.4b), after the absences were capped: what is forced
     * is what the payslip pays.
     *
     * @param list<PayrollItemSpec> $taken the rubriques of gross pay the payslip takes, in the plan's order
     * @param list<PayslipRow>      $rows
     *
     * @return list<PayslipRow>
     */
    private static function adjusted(array $taken, array $rows, PayslipAdjustments $adjustments): array
    {
        if ($adjustments->isEmpty()) {
            return $rows;
        }
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row->code] = $row;
        }
        $adjusted = [];
        foreach ($taken as $spec) {
            $row = $adjustments->apply($spec, $byCode[$spec->code] ?? null);
            if (null !== $row) {
                $adjusted[] = $row;
            }
        }

        return $adjusted;
    }

    /**
     * @param list<PayrollItemSpec> $ordered
     * @param list<PayslipRow>      $rows
     *
     * @return list<PayslipRow>
     */
    private static function inPlanOrder(array $ordered, array $rows): array
    {
        $place = array_flip(array_map(static fn (PayrollItemSpec $spec): string => $spec->code, $ordered));
        usort($rows, static fn (PayslipRow $a, PayslipRow $b): int => ($place[$a->code] ?? 0) <=> ($place[$b->code] ?? 0));

        return $rows;
    }

    private static function grossRow(PayrollItemSpec $spec, PayslipInput $input): ?PayslipRow
    {
        $employee = $input->employee;
        $variables = $input->variables;

        switch ($spec->base) {
            case PayrollBase::ContractHours:
                $rate = self::hourlyRate($spec, $input);
                if (PayrollRateSource::HourlyRate === $spec->employeeRate->getSource()) {
                    // Mensualisation: the salary of the contract, whatever the hourly rate rounds to.
                    return self::row($spec, $employee->monthlyHours, PayslipUnit::Hours, $rate['rate'], PayslipUnit::EurosPerHour, $employee->baseSalary, new PayslipExplanation(
                        \sprintf('Salaire mensualisé : %s pour %s (%s × %s)', FrenchFormat::amountInEuros($employee->baseSalary), PayslipUnit::Hours->format($employee->monthlyHours), PayslipUnit::Hours->format($employee->monthlyHours), PayslipUnit::EurosPerHour->format($rate['rate'])),
                        'Horaire mensuel du contrat : '.PayslipUnit::Hours->format($employee->monthlyHours).'.',
                        $rate['origin'],
                        $rate['source'],
                    ));
                }

                return self::times($spec, $employee->monthlyHours, PayslipUnit::Hours, 'Horaire mensuel du contrat.', $rate['rate'], PayslipUnit::EurosPerHour, $rate['origin'], $rate['source']);

            case PayrollBase::EnteredHours:
                $hours = $variables->quantity($spec->code);
                if (null === $hours) {
                    return null;
                }
                $rate = self::hourlyRate($spec, $input);

                return self::times($spec, $hours, PayslipUnit::Hours, 'Heures saisies dans les éléments du mois.', $rate['rate'], PayslipUnit::EurosPerHour, $rate['origin'], $rate['source']);

            case PayrollBase::BaseSalary:
                $percent = self::percent($spec, $input);
                if (null === $percent) {
                    return null;
                }

                return self::times($spec, $employee->baseSalary->toDecimal(), PayslipUnit::Euros, 'Salaire de base du contrat.', $percent['rate'], PayslipUnit::Percent, $percent['origin'], $percent['source']);

            case PayrollBase::EnteredCount:
                $count = $variables->quantity($spec->code);
                if (null === $count) {
                    return null;
                }
                $price = self::unitPrice($spec, $input);

                return self::times($spec, $count, PayslipUnit::Count, 'Nombre saisi dans les éléments du mois.', $price['rate'], PayslipUnit::EurosPerUnit, $price['origin'], $price['source']);

            case PayrollBase::EnteredAmount:
                $amount = $variables->amount($spec->code);
                if (null === $amount) {
                    return null;
                }
                $signed = $spec->subtracts ? $amount->negated() : $amount;

                return self::row($spec, null, null, null, null, $signed, new PayslipExplanation(
                    'Montant saisi : '.FrenchFormat::amountInEuros($amount),
                    'Montant saisi dans les éléments du mois.',
                    'Sans taux : la rubrique est un montant.',
                ));

            case PayrollBase::PaidLeaveHours:
                $leave = $variables->paidLeave;
                if (null === $leave || $leave->hours->isZero()) {
                    return null;
                }
                $rate = self::hourlyRate($spec, $input);

                return self::times($spec, $leave->hours, PayslipUnit::Hours, 'Heures de congés payés prises dans le mois.', $rate['rate'], PayslipUnit::EurosPerHour, $rate['origin'], $rate['source']);

            case PayrollBase::PaidLeavePay:
                return null === $variables->paidLeave ? null : self::paidLeavePay($spec, $input, $variables->paidLeave);

            case PayrollBase::EntryExitDays:
                return self::entryExitRow($spec, $input);

            case PayrollBase::SickPay:
                $hours = $variables->quantity($spec->code);

                return null === $hours ? null : self::sickPayRow($spec, $input, $hours);

            case PayrollBase::Dismissal:
            case PayrollBase::ConventionalBreak:
                return self::severanceRow($spec, $input);

            default:
                throw new \LogicException(\sprintf('Rubrique %s : un élément de brut ne repose pas sur « %s ».', $spec->code, $spec->base->label()));
        }
    }

    /**
     * The pay of the paid leave taken: the better of the salary kept - the hours at the hourly rate
     * of the contract, what they take off the salary - and the tenth of the reference period's gross
     * pay for the days taken out of the days acquired.
     */
    private static function paidLeavePay(PayrollItemSpec $spec, PayslipInput $input, PaidLeave $leave, ?Money $month = null): PayslipRow
    {
        $hourly = $input->employee->hourlyRate();
        $kept = PayslipUnit::EurosPerHour->times($leave->hours, $hourly);
        $tenth = self::percent($spec, $input) ?? throw new \LogicException(\sprintf('Rubrique %s : la règle du dixième n’a pas de taux.', $spec->code));
        // The compensatory indemnity's reference adds the last month's gross pay to the months before it.
        $reference = null === $month ? $leave->referenceGross : $leave->referenceGross->plus($month);
        $share = $reference->toDecimal()->multipliedBy($leave->daysTaken)->dividedBy($leave->daysAcquired, 2, RoundingMode::HalfUp);
        $tenthPay = PayslipUnit::Percent->times($share, $tenth['rate']);
        $days = null === $month ? 'jours pris' : 'jours restants';
        $referenceText = null === $month
            ? PayslipUnit::Euros->format($reference->toDecimal())
            : \sprintf('(%s + %s du dernier mois)', PayslipUnit::Euros->format($leave->referenceGross->toDecimal()), PayslipUnit::Euros->format($month->toDecimal()));

        $keptText = \sprintf('Maintien de salaire : %s × %s = %s', PayslipUnit::Hours->format($leave->hours), PayslipUnit::EurosPerHour->format($hourly), PayslipUnit::Euros->format($kept));
        $tenthText = \sprintf('Dixième : %s × %s ÷ %s jours acquis = %s ; × %s = %s', $referenceText, FrenchFormat::number($leave->daysTaken).' '.$days, FrenchFormat::number($leave->daysAcquired), PayslipUnit::Euros->format($share), PayslipUnit::Percent->format($tenth['rate']), PayslipUnit::Euros->format($tenthPay));
        $notes = [$keptText.'.', $tenthText.'.', 'Le plus favorable des deux est versé.'];
        if (null !== $month) {
            $notes[] = 'La période de référence comprend le dernier mois, indemnité de fin de contrat comprise.';
        }

        if ($tenthPay->isGreaterThan($kept)) {
            return self::row($spec, $share, PayslipUnit::Euros, $tenth['rate'], PayslipUnit::Percent, Money::euros($tenthPay), new PayslipExplanation(
                self::formula($share, PayslipUnit::Euros, $tenth['rate'], PayslipUnit::Percent, $tenthPay),
                \sprintf('Rémunération brute de la période de référence (%s) pour %s %s sur %s acquis.', PayslipUnit::Euros->format($reference->toDecimal()), FrenchFormat::number($leave->daysTaken), $days, FrenchFormat::number($leave->daysAcquired)),
                'Règle du dixième : '.$tenth['origin'],
                $tenth['source'],
                $notes,
            ));
        }

        return self::row($spec, $leave->hours, PayslipUnit::Hours, $hourly, PayslipUnit::EurosPerHour, Money::euros($kept), new PayslipExplanation(
            self::formula($leave->hours, PayslipUnit::Hours, $hourly, PayslipUnit::EurosPerHour, $kept),
            null === $month ? 'Heures de congés payés prises dans le mois : le salaire qu’elles auraient payé est maintenu.' : 'Heures que représentent les jours de congés acquis et non pris : le salaire qu’elles auraient payé.',
            self::contractRateOrigin($input->employee),
            $tenth['source'],
            $notes,
        ));
    }

    /**
     * The days of the month outside the contract, taken off at the calendar day's rate - the salary over
     * the days of the month, four decimals as the hourly rate (DECISIONS 4.3d). None in a whole month.
     */
    private static function entryExitRow(PayrollItemSpec $spec, PayslipInput $input): ?PayslipRow
    {
        $month = PayslipMonth::of($input);
        if ($month->isComplete()) {
            return null;
        }
        $employee = $input->employee;
        $days = BigDecimal::of($month->outsideDays());
        $daily = $employee->baseSalary->toDecimal()->dividedBy($month->days, 4, RoundingMode::HalfUp);
        $amount = PayslipUnit::EurosPerUnit->times($days, $daily);
        $period = [];
        if ($month->from > $month->first) {
            $period[] = \sprintf('entrée le %s', FrenchFormat::date($month->from));
        }
        if ($month->to < $month->last) {
            $period[] = \sprintf('dernier jour le %s', FrenchFormat::date($month->to));
        }

        return self::row($spec, $days, PayslipUnit::Days, $daily, PayslipUnit::EurosPerUnit, Money::euros($amount)->negated(), new PayslipExplanation(
            self::formula($days, PayslipUnit::Days, $daily, PayslipUnit::EurosPerUnit, $amount).', en déduction',
            \sprintf('Jours calendaires du mois hors contrat (%s) : %d jours sur %d, le contrat en couvre %d.', implode(', ', $period), $month->outsideDays(), $month->days, $month->presentDays),
            \sprintf('Taux journalier : salaire du contrat ÷ jours du mois = %s ÷ %d = %s.', FrenchFormat::amountInEuros($employee->baseSalary), $month->days, PayslipUnit::EurosPerUnit->format($daily)),
        ));
    }

    /**
     * The end-of-contract indemnity of a fixed-term contract reaching its term (L1243-8 of the code du
     * travail, service-public F803): its rate on the contract's gross pay - the months before, entered on
     * the rubrique, and this month's, before the indemnities. None for any other end (an early termination,
     * the trial period), nor before the last month.
     */
    private static function fixedTermRow(PayrollItemSpec $spec, PayslipInput $input, Money $month): ?PayslipRow
    {
        if (ExitReason::FixedTermEnd !== $input->employee->exitReason || !PayslipSeverance::endsThisMonth($input)) {
            return null;
        }
        $rate = self::percent($spec, $input) ?? throw new \LogicException(\sprintf('Rubrique %s : l’indemnité de fin de contrat n’a pas de taux.', $spec->code));
        $before = $input->variables->amount($spec->code) ?? Money::zero();
        $base = $before->plus($month)->toDecimal();

        return self::times($spec, $base, PayslipUnit::Euros, \sprintf('Rémunération brute du contrat : %s des mois précédents (saisis) + %s du dernier mois, avant les indemnités = %s.', FrenchFormat::amountInEuros($before), FrenchFormat::amountInEuros($month), PayslipUnit::Euros->format($base)), $rate['rate'], PayslipUnit::Percent, $rate['origin'].' Due au terme du contrat ; ni en cas de rupture anticipée, ni pendant la période d’essai, ni si le salarié refuse un CDI proposé (retirez alors la ligne).', $rate['source']);
    }

    /**
     * The compensatory paid leave indemnity: the paid leave's rule on the days acquired and not taken,
     * whose tenth counts the last month's gross pay.
     */
    private static function compensatoryLeaveRow(PayrollItemSpec $spec, PayslipInput $input, Money $month): ?PayslipRow
    {
        $leave = $input->variables->compensatoryLeave;

        return null === $leave ? null : self::paidLeavePay($spec, $input, $leave, $month);
    }

    /** The dismissal or conventional termination indemnity, exempt: its explanation carries its regime. */
    private static function severanceRow(PayrollItemSpec $spec, PayslipInput $input): ?PayslipRow
    {
        $severance = PayslipSeverance::of($input);
        if (null === $severance || $severance->base !== $spec->base) {
            return null;
        }
        $agreed = PayrollBase::ConventionalBreak === $spec->base && !$severance->amount->equals($severance->legal);

        return self::row($spec, null, null, null, null, $severance->amount, new PayslipExplanation(
            $agreed ? 'Indemnité convenue : '.FrenchFormat::amountInEuros($severance->amount).', au moins l’indemnité légale ('.FrenchFormat::amountInEuros($severance->legal).')' : 'Indemnité légale : '.FrenchFormat::amountInEuros($severance->amount),
            $severance->legalText,
            'Sans taux : versée hors du brut, exonérée de cotisations ; son régime est dans les notes.',
            PayslipSeverance::source($input),
            $severance->notes,
        ));
    }

    /**
     * The sick pay the employer adds (L1226-1, D1226-1 of the code du travail): the hours whose salary
     * is kept, at the contract's hourly rate times the item's rate (90 %, four decimals), less the daily
     * allowances of the social security for those days, entered beside the hours - never below zero. The
     * hours are the user's: who has a right to it and from which day is read on the stop, not here.
     */
    private static function sickPayRow(PayrollItemSpec $spec, PayslipInput $input, BigDecimal $hours): PayslipRow
    {
        $hourly = $input->employee->hourlyRate();
        $share = self::percent($spec, $input) ?? throw new \LogicException(\sprintf('Rubrique %s : le maintien de salaire n’a pas de taux.', $spec->code));
        $rate = $hourly->multipliedBy($share['rate'])->dividedBy(100, 4, RoundingMode::HalfUp);
        $kept = PayslipUnit::EurosPerHour->times($hours, $rate);
        $allowances = $input->variables->amount($spec->code) ?? Money::zero();
        $amount = Money::euros($kept)->minus($allowances);
        $notes = [];
        if ($amount->isNegative()) {
            $notes[] = 'Les indemnités journalières dépassent le salaire maintenu : l’employeur n’ajoute rien.';
            $amount = Money::zero();
        }

        return self::row($spec, $hours, PayslipUnit::Hours, $rate, PayslipUnit::EurosPerHour, $amount, new PayslipExplanation(
            \sprintf('%s, moins %s d’indemnités journalières = %s', self::formula($hours, PayslipUnit::Hours, $rate, PayslipUnit::EurosPerHour, $kept), FrenchFormat::amountInEuros($allowances), FrenchFormat::amountInEuros($amount)),
            'Heures d’absence dont le salaire est maintenu, saisies dans les éléments du mois, et indemnités journalières brutes de la sécurité sociale pour ces jours (relevé de la caisse).',
            \sprintf('%s × %s = %s. %s', self::contractRateOrigin($input->employee), PayslipUnit::Percent->format($share['rate']), PayslipUnit::EurosPerHour->format($rate), $share['origin']),
            $share['source'],
            $notes,
        ));
    }

    /**
     * The rate of an item counted in hours: the hourly rate of the contract, raised by the premium
     * the item names (regulatory) or by its own percent of it.
     *
     * @return array{rate: BigDecimal, origin: string, source: ?PayslipSource}
     */
    private static function hourlyRate(PayrollItemSpec $spec, PayslipInput $input): array
    {
        $hourly = $input->employee->hourlyRate();
        $side = $spec->employeeRate;

        return match ($side->getSource()) {
            PayrollRateSource::HourlyRate => ['rate' => $hourly, 'origin' => self::contractRateOrigin($input->employee), 'source' => null],
            PayrollRateSource::HourlyRateWithPremium => (static function () use ($input, $hourly, $side): array {
                $key = (string) $side->getReferenceKey();
                $premium = $input->regulatory->decimal($key, ...$side->pathSegments());
                $rate = $hourly->multipliedBy($premium->plus(100))->dividedBy(100, 4, RoundingMode::HalfUp);

                return ['rate' => $rate, 'origin' => \sprintf('%s majoré de %s : %s × (1 + %s) = %s. %s', self::contractRateOrigin($input->employee), PayslipUnit::Percent->format($premium), PayslipUnit::EurosPerHour->format($hourly), PayslipUnit::Percent->format($premium), PayslipUnit::EurosPerHour->format($rate), 'Majoration réglementaire du millésime.'), 'source' => PayslipSource::of($input->regulatory, $key, $side->getReferencePath())];
            })(),
            PayrollRateSource::Fixed => (static function () use ($input, $hourly, $side): array {
                $percent = BigDecimal::of((string) $side->getPercent());
                $rate = $hourly->multipliedBy($percent)->dividedBy(100, 4, RoundingMode::HalfUp);

                return ['rate' => $rate, 'origin' => \sprintf('%s × %s (taux du dossier) = %s.', self::contractRateOrigin($input->employee), PayslipUnit::Percent->format($percent), PayslipUnit::EurosPerHour->format($rate)), 'source' => null];
            })(),
            default => throw new \LogicException(\sprintf('Rubrique %s : « %s » ne donne pas un taux horaire.', $spec->code, $side->getSource()->label())),
        };
    }

    /**
     * A percent: entered in the month, the dossier's own, or a regulatory value. Null when the
     * month gives none for an item whose rate is entered.
     *
     * @return array{rate: BigDecimal, origin: string, source: ?PayslipSource}|null
     */
    private static function percent(PayrollItemSpec $spec, PayslipInput $input): ?array
    {
        $side = $spec->employeeRate;

        switch ($side->getSource()) {
            case PayrollRateSource::Entered:
                $rate = $input->variables->rate($spec->code);

                return null === $rate ? null : ['rate' => $rate, 'origin' => 'Taux saisi dans les éléments du mois : '.PayslipUnit::Percent->format($rate).'.', 'source' => null];
            case PayrollRateSource::Fixed:
                $rate = BigDecimal::of((string) $side->getPercent());

                return ['rate' => $rate, 'origin' => 'Taux du dossier : '.PayslipUnit::Percent->format($rate).'.', 'source' => null];
            case PayrollRateSource::Regulatory:
                $key = (string) $side->getReferenceKey();
                $rate = $input->regulatory->decimal($key, ...$side->pathSegments());

                return ['rate' => $rate, 'origin' => self::regulatoryOrigin($input, $key, $side->getReferencePath(), PayslipUnit::Percent->format($rate)), 'source' => PayslipSource::of($input->regulatory, $key, $side->getReferencePath())];
            default:
                throw new \LogicException(\sprintf('Rubrique %s : « %s » ne donne pas un pourcentage.', $spec->code, $side->getSource()->label()));
        }
    }

    /**
     * The price of one unit counted: a regulatory amount (a meal provided) or the dossier's.
     *
     * @return array{rate: BigDecimal, origin: string, source: ?PayslipSource}
     */
    private static function unitPrice(PayrollItemSpec $spec, PayslipInput $input): array
    {
        $side = $spec->employeeRate;
        if (PayrollRateSource::Amount === $side->getSource() && null !== $side->getAmount()) {
            $price = $side->getAmount()->toDecimal();

            return ['rate' => $price, 'origin' => 'Montant unitaire du dossier : '.PayslipUnit::EurosPerUnit->format($price).'.', 'source' => null];
        }
        if (PayrollRateSource::Regulatory === $side->getSource()) {
            $key = (string) $side->getReferenceKey();
            $price = $input->regulatory->decimal($key, ...$side->pathSegments());

            return ['rate' => $price, 'origin' => self::regulatoryOrigin($input, $key, $side->getReferencePath(), PayslipUnit::EurosPerUnit->format($price)), 'source' => PayslipSource::of($input->regulatory, $key, $side->getReferencePath())];
        }

        throw new \LogicException(\sprintf('Rubrique %s : « %s » ne donne pas un montant unitaire.', $spec->code, $side->getSource()->label()));
    }

    private static function times(PayrollItemSpec $spec, BigDecimal $base, PayslipUnit $baseUnit, string $baseOrigin, BigDecimal $rate, PayslipUnit $rateUnit, string $rateOrigin, ?PayslipSource $source): PayslipRow
    {
        $amount = $rateUnit->times($base, $rate);
        $money = Money::euros($amount);

        return self::row($spec, $base, $baseUnit, $rate, $rateUnit, $spec->subtracts ? $money->negated() : $money, new PayslipExplanation(
            self::formula($base, $baseUnit, $rate, $rateUnit, $amount).($spec->subtracts ? ', en déduction' : ''),
            $baseOrigin,
            $rateOrigin,
            $source,
        ));
    }

    private static function row(PayrollItemSpec $spec, ?BigDecimal $base, ?PayslipUnit $baseUnit, ?BigDecimal $rate, ?PayslipUnit $rateUnit, Money $amount, PayslipExplanation $explanation): PayslipRow
    {
        return new PayslipRow($spec->code, $spec->label, $spec->kind, $base, $baseUnit, $rate, $rateUnit, $amount, $explanation);
    }

    private static function formula(BigDecimal $base, PayslipUnit $baseUnit, BigDecimal $rate, PayslipUnit $rateUnit, BigDecimal $amount): string
    {
        return \sprintf('%s × %s = %s', $baseUnit->format($base), $rateUnit->format($rate), PayslipUnit::Euros->format($amount));
    }

    private static function contractRateOrigin(PayslipEmployee $employee): string
    {
        return \sprintf('Taux horaire du contrat : %s ÷ %s = %s', FrenchFormat::amountInEuros($employee->baseSalary), PayslipUnit::Hours->format($employee->monthlyHours), PayslipUnit::EurosPerHour->format($employee->hourlyRate()));
    }

    private static function regulatoryOrigin(PayslipInput $input, string $key, ?string $path, string $value): string
    {
        $read = $input->regulatory->value($key);

        return \sprintf('%s : %s au %s (%s%s).', $read->getLabel(), $value, FrenchFormat::date($input->regulatory->day), $key, null === $path ? '' : ' › '.str_replace('.', ' › ', $path));
    }
}
