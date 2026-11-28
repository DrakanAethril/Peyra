<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Service\Payroll\Engine\PaidLeave;
use App\Service\Payroll\Engine\Severance;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * Reads the « Éléments du mois » card as typed (step 4.4a) into PayslipElements, or says what is
 * wrong, field by field. A pure function: an empty field is « nothing this month »; hours, numbers
 * and percents are not negative and carry two decimals at most (four for a percent), an amount is
 * in euros and cents; the paid leave is all four of its figures or none; the days without any pay
 * are whole days of the month. On the last payslip of a contract (step 4.4c, LastPayslipEntries) the
 * leave acquired and not taken is read like the leave taken, and a dismissal's or a conventional
 * termination's indemnity needs its reference salary; the amount agreed and the previous year's gross
 * pay are optional.
 */
final class PayslipElementsRules
{
    public const array PAID_LEAVE = ['hours', 'daysTaken', 'daysAcquired', 'referenceGross'];
    public const array SEVERANCE = ['referenceSalary', 'agreed', 'previousYearGross'];

    /**
     * @param list<PayslipElementField> $fields
     * @param array<string, string>     $typed  by field key (« quantity.1100 »), « paidLeave.hours »…, « unpaidDays »
     *
     * @return array{elements: ?PayslipElements, errors: array<string, string>}
     */
    public static function read(array $fields, bool $takesPaidLeave, array $typed, ?LastPayslipEntries $last = null): array
    {
        $last ??= LastPayslipEntries::none();
        $errors = [];
        $quantities = $amounts = $rates = [];
        foreach ($fields as $field) {
            $value = self::number($typed[$field->key()] ?? '', PayslipElementField::RATE === $field->kind ? 4 : 2, $field->key(), $errors);
            if (null === $value || $value->isZero()) {
                continue;
            }
            if (PayslipElementField::RATE === $field->kind && $value->isGreaterThan(100)) {
                $errors[$field->key()] = 'Un pourcentage est compris entre 0 et 100.';
                continue;
            }
            match ($field->kind) {
                PayslipElementField::QUANTITY => $quantities[$field->code] = $value,
                PayslipElementField::AMOUNT => $amounts[$field->code] = Money::euros($value),
                default => $rates[$field->code] = $value,
            };
        }

        $paidLeave = $takesPaidLeave ? self::leave('paidLeave', $typed, $errors, 'Des congés payés pris se décrivent par leurs heures, les jours pris, les jours acquis et le brut de la période de référence.', 'Les jours pris sont plus de zéro et pas plus que les jours acquis.') : null;
        $compensatoryLeave = $last->compensatoryLeave ? self::leave('compensatoryLeave', $typed, $errors, 'Des congés acquis et non pris se décrivent par leurs heures, les jours restants, les jours acquis et le brut de la période de référence avant ce mois.', 'Les jours restants sont plus de zéro et pas plus que les jours acquis.') : null;
        $severance = null;
        if ($last->severance) {
            $parts = [];
            foreach (self::SEVERANCE as $part) {
                $parts[$part] = self::number($typed['severance.'.$part] ?? '', 2, 'severance.'.$part, $errors);
            }
            if (null === $parts['referenceSalary'] && !isset($errors['severance.referenceSalary'])) {
                $errors['severance.referenceSalary'] = 'Le salaire de référence de l’indemnité est à saisir : le plus favorable des moyennes des douze et des trois derniers mois.';
            } elseif (null !== $parts['referenceSalary'] && !$parts['referenceSalary']->isPositive()) {
                $errors['severance.referenceSalary'] = 'Le salaire de référence est supérieur à zéro.';
            } elseif (null !== $parts['referenceSalary']) {
                $optional = static fn (?BigDecimal $value): ?Money => null === $value ? null : Money::euros($value);
                $severance = new Severance(Money::euros($parts['referenceSalary']), $last->agreed ? $optional($parts['agreed']) : null, $optional($parts['previousYearGross']));
            }
        }

        $unpaidDays = 0;
        $days = trim($typed['unpaidDays'] ?? '');
        if ('' !== $days) {
            if (1 !== preg_match('/^\d{1,2}$/', $days) || (int) $days > 31) {
                $errors['unpaidDays'] = 'Un nombre de jours entiers du mois, de 0 à 31.';
            } else {
                $unpaidDays = (int) $days;
            }
        }

        return [] === $errors
            ? ['elements' => new PayslipElements($quantities, $amounts, $rates, $paidLeave, $unpaidDays, $compensatoryLeave, $severance), 'errors' => []]
            : ['elements' => null, 'errors' => $errors];
    }

    /**
     * The four figures of a leave (`paidLeave` or `compensatoryLeave`), all or none.
     *
     * @param array<string, string> $typed
     * @param array<string, string> $errors
     */
    private static function leave(string $prefix, array $typed, array &$errors, string $incomplete, string $days): ?PaidLeave
    {
        $leave = [];
        foreach (self::PAID_LEAVE as $part) {
            $leave[$part] = self::number($typed[$prefix.'.'.$part] ?? '', 2, $prefix.'.'.$part, $errors);
        }
        $given = \count(array_filter($leave, static fn (?BigDecimal $v): bool => null !== $v));
        $typedWrong = [] !== array_filter(array_keys($errors), static fn (string $k): bool => str_starts_with($k, $prefix.'.'));
        if (!$typedWrong && $given > 0 && $given < \count(self::PAID_LEAVE)) {
            $errors[$prefix] = $incomplete;
        } elseif (null !== $leave['hours'] && null !== $leave['daysTaken'] && null !== $leave['daysAcquired'] && null !== $leave['referenceGross']) {
            if (!$leave['daysTaken']->isPositive() || $leave['daysTaken']->isGreaterThan($leave['daysAcquired'])) {
                $errors[$prefix] = $days;
            } else {
                return new PaidLeave($leave['hours'], $leave['daysTaken'], $leave['daysAcquired'], Money::euros($leave['referenceGross']));
            }
        }

        return null;
    }

    /** @param array<string, string> $errors */
    private static function number(string $text, int $scale, string $key, array &$errors): ?BigDecimal
    {
        if ('' === trim($text)) {
            return null;
        }
        try {
            $value = DecimalParser::parse($text);
        } catch (DecimalParseError $error) {
            $errors[$key] = $error->getMessage();

            return null;
        }
        if ($value->isNegative()) {
            $errors[$key] = 'Un élément du mois n’est pas négatif : une absence est une rubrique qui se retranche.';

            return null;
        }
        if ($value->strippedOfTrailingZeros()->getScale() > $scale) {
            $errors[$key] = \sprintf('%d décimales au plus.', $scale);

            return null;
        }

        return $value;
    }
}
