<?php

declare(strict_types=1);

namespace App\Service\Payroll\Employee;

use App\Enum\Payroll\Sex;

/**
 * The shape of a social security number (NIR, « numéro d'inscription au répertoire »): 13
 * characters - sex, year and month of birth, department (2A and 2B for Corsica), commune, order -
 * then a two-digit key. Its first digits must agree with the civil status. **Its key is never
 * checked**: every NIR of Peyra is fictitious and invalid by construction (key « 00 », plan of
 * the demonstration), so that no real person's number is ever typed as a valid one.
 */
final class Nir
{
    /** What a person typed, upper-cased, spaces removed: « 1 94 04 42 147 118 00 » → « 194044214711800 ». */
    public static function normalize(string $typed): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $typed));
    }

    /** « 1 94 04 42 147 118 00 », for reading. */
    public static function format(string $nir): string
    {
        if (15 !== \strlen($nir)) {
            return $nir;
        }

        return implode(' ', [substr($nir, 0, 1), substr($nir, 1, 2), substr($nir, 3, 2), substr($nir, 5, 2), substr($nir, 7, 3), substr($nir, 10, 3), substr($nir, 13, 2)]);
    }

    /** Null when the number is well formed and agrees with the civil status, else the sentence to print under the field. */
    public static function fault(string $nir, ?Sex $sex, ?\DateTimeImmutable $birthDate): ?string
    {
        if (1 !== preg_match('/^[12]\d{4}(\d{2}|2A|2B)\d{6}\d{2}$/', $nir)) {
            return 'Un numéro de sécurité sociale compte 15 caractères : sexe (1 ou 2), année et mois de naissance, département, commune, ordre et clé.';
        }
        if (null !== $sex && $nir[0] !== $sex->nirDigit()) {
            return \sprintf('Le numéro commence par %s pour %s.', $sex->nirDigit(), Sex::Male === $sex ? 'un homme' : 'une femme');
        }
        if (null !== $birthDate) {
            if (substr($nir, 1, 2) !== $birthDate->format('y')) {
                return \sprintf('Les chiffres 2 et 3 du numéro sont l’année de naissance : %s.', $birthDate->format('y'));
            }
            $month = (int) substr($nir, 3, 2);
            if ($month >= 1 && $month <= 12 && substr($nir, 3, 2) !== $birthDate->format('m')) {
                return \sprintf('Les chiffres 4 et 5 du numéro sont le mois de naissance : %s.', $birthDate->format('m'));
            }
        }

        return null;
    }
}
