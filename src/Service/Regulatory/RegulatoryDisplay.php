<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Service\Shared\FrenchFormat;

/**
 * A regulatory value as the screens print it (Paramètres › Paramètres réglementaires): a number
 * in French with its unit (« 6,9 % », « 1 823,03 €/mois », « 0,3193 »), a day as « 01/01/2027 »,
 * a yes or no, a text as it is. Pure.
 */
final class RegulatoryDisplay
{
    private const string ISO_DAY = '/^\d{4}-\d{2}-\d{2}$/';

    /** The unit's names in French, for the units the millésime files use; any other is shown as written. */
    private const array UNIT_NAMES = [
        'percent' => 'Pourcentage',
        'percent of SMIC' => 'Pourcentage du SMIC',
        'EUR' => 'Euros',
        'EUR TTC' => 'Euros TTC',
        'EUR/an' => 'Euros par an',
        'EUR/mois' => 'Euros par mois',
        'EUR/jour' => 'Euros par jour',
        'EUR/heure' => 'Euros par heure',
        'EUR/repas' => 'Euros par repas',
        'coefficient' => 'Coefficient',
        'structure' => 'Structure',
        'text' => 'Texte',
        'formula' => 'Formule',
        'date' => 'Date',
    ];

    /** « 12,02 €/heure », « 6,9 % », « 0,3193 », « Oui », « 01/01/2027 », or the text. */
    public static function leaf(string|bool|null $value, string $unit): string
    {
        if (null === $value) {
            return '—';
        }
        if (\is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }
        if (RegulatoryTree::isNumber($value)) {
            $suffix = self::suffix($unit);

            return FrenchFormat::number($value).('' === $suffix ? '' : FrenchFormat::NBSP.$suffix);
        }
        if (1 === preg_match(self::ISO_DAY, $value)) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (false !== $day && $day->format('Y-m-d') === $value) {
                return FrenchFormat::date($day);
            }
        }

        return $value;
    }

    /** What a person edits: a number in French without its unit, a text as it is. */
    public static function editable(string|bool|null $value): string
    {
        if (null === $value || \is_bool($value)) {
            return null === $value ? '' : ($value ? '1' : '0');
        }

        return RegulatoryTree::isNumber($value) ? FrenchFormat::number($value) : $value;
    }

    /**
     * The sign printed after a number: « % », « % du SMIC », « € », « €/mois »; nothing for a
     * coefficient, nor for a unit that explains a mixed structure in words.
     */
    public static function suffix(string $unit): string
    {
        if (str_contains($unit, '(')) {
            // « percent (bornes en EUR, …) »: a unit that explains a mixed structure; no sign fits every number.
            return '';
        }
        if ('percent of SMIC' === $unit) {
            return '% du SMIC';
        }
        if (str_starts_with($unit, 'percent')) {
            return '%';
        }
        if (str_starts_with($unit, 'EUR')) {
            return '€'.substr($unit, 3);
        }

        return '';
    }

    /** « Pourcentage », « Euros par mois »; the unit as written when the files use another. */
    public static function unitName(string $unit): string
    {
        return self::UNIT_NAMES[$unit] ?? $unit;
    }
}
