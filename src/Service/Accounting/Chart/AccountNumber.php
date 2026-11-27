<?php

declare(strict_types=1);

namespace App\Service\Accounting\Chart;

/**
 * The shape of a general account's number in a dossier: digits only, 6 to 8 of them, starting with
 * its class (1 to 8). A reference account is copied at six digits (« 601 » → « 601000 »); a dossier
 * may open longer subdivisions. A third party is not an account: « 401SCIE » is its code.
 */
final class AccountNumber
{
    public const int COPIED_LENGTH = 6;
    public const int MIN_LENGTH = 6;
    public const int MAX_LENGTH = 8;

    /** What a person typed, spaces removed (« 601 000 » → « 601000 »). */
    public static function normalize(string $typed): string
    {
        return (string) preg_replace('/\s+/u', '', $typed);
    }

    /** Null when the number is well formed, else the sentence to print under the field. */
    public static function fault(string $number): ?string
    {
        if ('' === $number) {
            return 'Indiquez le numéro du compte.';
        }
        if (1 !== preg_match('/^\d+$/', $number)) {
            return 'Un numéro de compte ne s’écrit qu’avec des chiffres ; un compte de tiers comme 401SCIE est le code d’un tiers.';
        }
        $length = \strlen($number);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return \sprintf('Un numéro de compte compte de %d à %d chiffres (601000, 6011000…).', self::MIN_LENGTH, self::MAX_LENGTH);
        }
        if ('0' === $number[0] || '9' === $number[0]) {
            return 'Un numéro de compte commence par sa classe, de 1 à 8.';
        }

        return null;
    }

    /**
     * Collective by default (« collectif »: each line names a third party): suppliers and customers,
     * their bills, their advances, doubtful customers.
     */
    public static function isCollectiveByDefault(string $number): bool
    {
        return \in_array(substr($number, 0, 3), ['401', '403', '404', '409', '411', '413', '416', '419'], true);
    }

    /** Only an account of third parties (class 4) may be collective. */
    public static function mayBeCollective(string $number): bool
    {
        return str_starts_with($number, '4');
    }
}
