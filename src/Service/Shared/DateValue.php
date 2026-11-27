<?php

declare(strict_types=1);

namespace App\Service\Shared;

/**
 * A calendar day as Peyra's forms carry it: « 2026-11-12 » in the hidden field the date input fills
 * (Px:DateInput), « 12/11/2026 » when a person types it. Strict: 31/02 is not a day, and nothing
 * here reads the clock - an abbreviated entry (« 12 », « 1211 ») is completed in the browser
 * against the dossier's date, never against the server's.
 */
final class DateValue
{
    /** « 2026-11-12 », or null for anything that is not a real day. */
    public static function parse(string $iso): ?\DateTimeImmutable
    {
        return self::read('Y-m-d', '/^\d{4}-\d{2}-\d{2}$/', trim($iso));
    }

    /** « 12/11/2026 », or null for anything that is not a real day. */
    public static function parseFrench(string $text): ?\DateTimeImmutable
    {
        return self::read('d/m/Y', '#^\d{2}/\d{2}/\d{4}$#', trim($text));
    }

    private static function read(string $format, string $shape, string $text): ?\DateTimeImmutable
    {
        if (1 !== preg_match($shape, $text)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);
        if (false === $date || $date->format($format) !== $text) {
            return null;
        }

        return $date;
    }
}
