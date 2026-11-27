<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Enum\ThirdParty\ThirdPartyKind;

/**
 * The shape of a third party's code (« C0003 », « F0021 »): capitals and digits, 1 to 12; and the
 * next one a new third party is proposed - its kind's letter and the number after the highest one
 * already given, on four digits.
 */
final class ThirdPartyCode
{
    public const int MAX_LENGTH = 12;

    /** What a person typed, upper-cased, spaces removed. */
    public static function normalize(string $typed): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $typed));
    }

    /** Null when the code is well formed, else the sentence to print under the field. */
    public static function fault(string $code): ?string
    {
        if ('' === $code) {
            return 'Indiquez le code du tiers.';
        }
        if (1 !== preg_match('/^[A-Z0-9]{1,'.self::MAX_LENGTH.'}$/', $code)) {
            return \sprintf('Un code de tiers compte de 1 à %d lettres ou chiffres, sans accent (C0003, F0012).', self::MAX_LENGTH);
        }

        return null;
    }

    /**
     * The code proposed to a new third party of that kind: « C0027 » after « C0026 ».
     *
     * @param list<string> $existing every code of the dossier
     */
    public static function next(ThirdPartyKind $kind, array $existing): string
    {
        $prefix = $kind->codePrefix();
        $highest = 0;
        foreach ($existing as $code) {
            if (1 === preg_match('/^'.$prefix.'(\d+)$/', $code, $match)) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), 4, '0', \STR_PAD_LEFT);
    }
}
