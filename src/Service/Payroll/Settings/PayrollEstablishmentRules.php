<?php

declare(strict_types=1);

namespace App\Service\Payroll\Settings;

use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use Brick\Math\BigDecimal;

/**
 * What makes the establishment of a payroll (step 4.1), pure: a headcount of 0 or more with two
 * decimals at most (an average: « 8,69 »), a work accident rate above 0 up to 100 %, a mobility
 * rate likewise or nothing, a collective monthly time above 0 up to 300 hours with two decimals.
 */
final class PayrollEstablishmentRules
{
    public static function check(PayrollEstablishmentDraft $draft): ?PayrollEstablishmentFields
    {
        $draft->errors = [];
        $headcount = self::decimal($draft, 'headcount', 'Indiquez l’effectif.', 2);
        if (null !== $headcount && ($headcount->isNegative() || $headcount->isGreaterThan(99999))) {
            $draft->errors['headcount'] = 'Un effectif est un nombre positif.';
        }
        $accident = self::decimal($draft, 'workAccidentRate', 'Indiquez le taux d’accident du travail notifié.', 4);
        if (null !== $accident && (!$accident->isPositive() || $accident->isGreaterThan(100))) {
            $draft->errors['workAccidentRate'] = 'Un taux est compris entre 0 et 100 %.';
        }
        $mobility = '' === trim($draft->mobilityRate) ? null : self::decimal($draft, 'mobilityRate', '', 4);
        if (null !== $mobility && (!$mobility->isPositive() || $mobility->isGreaterThan(100))) {
            $draft->errors['mobilityRate'] = 'Un taux est compris entre 0 et 100 % ; laissez vide si aucun versement mobilité n’est dû.';
        }
        $hours = self::decimal($draft, 'monthlyHours', 'Indiquez l’horaire collectif mensuel.', 2);
        if (null !== $hours && (!$hours->isPositive() || $hours->isGreaterThan(300))) {
            $draft->errors['monthlyHours'] = 'Un horaire mensuel est compris entre 0 et 300 heures (151,67 pour 35 heures par semaine).';
        }
        if ([] !== $draft->errors || null === $headcount || null === $accident || null === $hours) {
            return null;
        }

        return new PayrollEstablishmentFields(self::text($headcount), self::text($accident), null === $mobility ? null : self::text($mobility), self::text($hours));
    }

    private static function decimal(PayrollEstablishmentDraft $draft, string $field, string $missing, int $maxScale): ?BigDecimal
    {
        $text = trim((string) $draft->{$field});
        if ('' === $text) {
            $draft->errors[$field] = $missing;

            return null;
        }
        try {
            $value = DecimalParser::parse($text);
        } catch (DecimalParseError $error) {
            $draft->errors[$field] = $error->getMessage();

            return null;
        }
        if ($value->strippedOfTrailingZeros()->getScale() > $maxScale) {
            $draft->errors[$field] = \sprintf('%d décimales au plus.', $maxScale);

            return null;
        }

        return $value;
    }

    private static function text(BigDecimal $value): string
    {
        return (string) $value->strippedOfTrailingZeros();
    }
}
