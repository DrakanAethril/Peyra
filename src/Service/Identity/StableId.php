<?php

declare(strict_types=1);

namespace App\Service\Identity;

/**
 * The directory id an account is keyed on (CLAUDE.md, « A dossier belongs to the student »):
 * the configured attribute (objectGUID in production, entryUUID in development), else uidNumber.
 * Written `<attribute>:<value>` so that the two sources never meet; a binary objectGUID is written
 * in its usual text form.
 */
final class StableId
{
    /**
     * @param array<string, list<string>> $attributes the entry's attributes, names in any case
     *
     * @return string|null null when the entry carries neither attribute
     */
    public static function of(array $attributes, string $preferred): ?string
    {
        foreach ([$preferred, 'uidNumber'] as $name) {
            $value = self::first($attributes, $name);
            if (null !== $value && '' !== $value) {
                return $name.':'.('objectguid' === strtolower($name) ? self::guid($value) : $value);
            }
        }

        return null;
    }

    /**
     * The text form of a Windows GUID read as its 16 raw bytes: the first three fields are
     * little-endian (« 00112233-4455-6677-8899-aabbccddeeff » is stored 33 22 11 00 55 44 77 66 …).
     * A value that is already text is kept as is.
     */
    public static function guid(string $value): string
    {
        if (16 !== \strlen($value) || 1 === preg_match('/^[\x20-\x7e]{16}$/', $value)) {
            return strtolower($value);
        }
        $hex = bin2hex($value);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            implode('', array_reverse(str_split(substr($hex, 0, 8), 2))),
            implode('', array_reverse(str_split(substr($hex, 8, 4), 2))),
            implode('', array_reverse(str_split(substr($hex, 12, 4), 2))),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /** @param array<string, list<string>> $attributes */
    private static function first(array $attributes, string $name): ?string
    {
        foreach ($attributes as $key => $values) {
            if (0 === strcasecmp($key, $name)) {
                return $values[0] ?? null;
            }
        }

        return null;
    }
}
