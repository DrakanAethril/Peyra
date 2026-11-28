<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

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
 * Step 4.3a calculates the gross pay:
 * - the salary of the contract, mensualised: its monthly hours at the hourly rate, the amount being
 *   the salary itself (an hourly rate rounded to four decimals would miss it by a few cents);
 * - hours entered (overtime, complementary hours, an absence) at the hourly rate, raised by the
 *   premium the rubrique names; an absence is taken off at the hourly rate of the contract (the
 *   method retained, DECISIONS 4.3a);
 * - a percent of the base salary (the seniority bonus, its rate entered in the month);
 * - a number at a price (meals provided, valued at the regulatory amount);
 * - an amount entered (a bonus, a company car);
 * - the paid leave: its hours off the salary, and its pay, the better of the salary kept and the
 *   tenth of the reference period's gross pay for the days taken (L3141-24).
 */
final class PayslipCalculator
{
    public static function calculate(PayslipInput $input): Payslip
    {
        $rows = [];
        foreach (self::ordered($input->plan) as $spec) {
            if (PayrollItemKind::Gross !== $spec->kind || !$spec->status->holds($input->employee->executive)) {
                continue;
            }
            $row = self::grossRow($spec, $input);
            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return new Payslip($rows, Money::sum(array_map(static fn (PayslipRow $row): Money => $row->employeeAmount, $rows)));
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

            default:
                throw new \LogicException(\sprintf('Rubrique %s : un élément de brut ne repose pas sur « %s ».', $spec->code, $spec->base->label()));
        }
    }

    /**
     * The pay of the paid leave taken: the better of the salary kept - the hours at the hourly rate
     * of the contract, what they take off the salary - and the tenth of the reference period's gross
     * pay for the days taken out of the days acquired.
     */
    private static function paidLeavePay(PayrollItemSpec $spec, PayslipInput $input, PaidLeave $leave): PayslipRow
    {
        $hourly = $input->employee->hourlyRate();
        $kept = PayslipUnit::EurosPerHour->times($leave->hours, $hourly);
        $tenth = self::percent($spec, $input) ?? throw new \LogicException(\sprintf('Rubrique %s : la règle du dixième n’a pas de taux.', $spec->code));
        $share = $leave->referenceGross->toDecimal()->multipliedBy($leave->daysTaken)->dividedBy($leave->daysAcquired, 2, RoundingMode::HalfUp);
        $tenthPay = PayslipUnit::Percent->times($share, $tenth['rate']);

        $keptText = \sprintf('Maintien de salaire : %s × %s = %s', PayslipUnit::Hours->format($leave->hours), PayslipUnit::EurosPerHour->format($hourly), PayslipUnit::Euros->format($kept));
        $tenthText = \sprintf('Dixième : %s × %s ÷ %s jours acquis = %s ; × %s = %s', PayslipUnit::Euros->format($leave->referenceGross->toDecimal()), FrenchFormat::number($leave->daysTaken).' jours pris', FrenchFormat::number($leave->daysAcquired), PayslipUnit::Euros->format($share), PayslipUnit::Percent->format($tenth['rate']), PayslipUnit::Euros->format($tenthPay));
        $notes = [$keptText.'.', $tenthText.'.', 'Le plus favorable des deux est versé.'];

        if ($tenthPay->isGreaterThan($kept)) {
            return self::row($spec, $share, PayslipUnit::Euros, $tenth['rate'], PayslipUnit::Percent, Money::euros($tenthPay), new PayslipExplanation(
                self::formula($share, PayslipUnit::Euros, $tenth['rate'], PayslipUnit::Percent, $tenthPay),
                \sprintf('Rémunération brute de la période de référence (%s) pour %s jours pris sur %s acquis.', PayslipUnit::Euros->format($leave->referenceGross->toDecimal()), FrenchFormat::number($leave->daysTaken), FrenchFormat::number($leave->daysAcquired)),
                'Règle du dixième : '.$tenth['origin'],
                $tenth['source'],
                $notes,
            ));
        }

        return self::row($spec, $leave->hours, PayslipUnit::Hours, $hourly, PayslipUnit::EurosPerHour, Money::euros($kept), new PayslipExplanation(
            self::formula($leave->hours, PayslipUnit::Hours, $hourly, PayslipUnit::EurosPerHour, $kept),
            'Heures de congés payés prises dans le mois : le salaire qu’elles auraient payé est maintenu.',
            self::contractRateOrigin($input->employee),
            $tenth['source'],
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
