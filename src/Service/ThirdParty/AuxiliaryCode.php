<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Service\Accounting\Chart\AccountSearch;

/**
 * The auxiliary code of a third party kept on a collective account (« 401SCIE »): what an entry
 * line types in the Compte column, and the FEC's CompAuxNum. Capitals and digits, 2 to 17, with at
 * least one letter - so that it is never read as an account number, which has digits only.
 */
final class AuxiliaryCode
{
    public const int MAX_LENGTH = 17;

    /** What a person typed, upper-cased, spaces removed. */
    public static function normalize(string $typed): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $typed));
    }

    /** Null when the code is well formed, else the sentence to print under the field. */
    public static function fault(string $code): ?string
    {
        if (1 !== preg_match('/^[A-Z0-9]{2,'.self::MAX_LENGTH.'}$/', $code)) {
            return \sprintf('Un code auxiliaire compte de 2 à %d lettres ou chiffres, sans accent (401SCIE).', self::MAX_LENGTH);
        }
        if (1 !== preg_match('/[A-Z]/', $code)) {
            return 'Un code auxiliaire porte au moins une lettre (401SCIE) : un nombre seul serait un numéro de compte.';
        }

        return null;
    }

    /**
     * The code proposed when none is typed: the collective's three first digits and the four first
     * letters of the name's first meaningful word (« 401 » + « SCIE »), then a number when it is
     * taken (« 401SCIE2 »).
     *
     * @param callable(string): bool $taken
     */
    public static function suggest(string $collectiveNumber, string $name, callable $taken): string
    {
        $words = array_values(array_filter(
            explode(' ', strtoupper(AccountSearch::fold($name))),
            static fn (string $word): bool => 1 === preg_match('/^[A-Z]{2,}$/', $word) && !\in_array($word, ['LE', 'LA', 'LES', 'DU', 'DE', 'DES', 'ET', 'SA', 'SAS', 'SARL', 'EURL', 'SCI'], true),
        ));
        $letters = substr($words[0] ?? 'TIERS', 0, 4);
        $base = substr($collectiveNumber, 0, 3).$letters;
        $code = $base;
        for ($n = 2; $taken($code); ++$n) {
            $code = $base.$n;
        }

        return $code;
    }
}
