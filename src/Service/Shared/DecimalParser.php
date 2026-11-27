<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Brick\Math\BigDecimal;

/**
 * Reads a number typed by a person - a rate, a coefficient, a threshold (« 6,90 », « 0.3193 »,
 * « 48 060 », « −2,5 ») - into an exact decimal, or refuses it with a sentence. Unlike an amount
 * (AmountParser), it keeps every decimal it is given: a rate has the precision of its text.
 *
 * - spaces of any kind are ignored, and so are a trailing « % » or « € »;
 * - one sign at the head: « - », « − » (U+2212) or « + »;
 * - a lone comma or point is the decimal separator; when both appear, the last one is, and the
 *   other separates thousands; a separator repeated alone separates thousands.
 */
final class DecimalParser
{
    /** Far above any regulatory value, far below what a person means to type. */
    private const int MAX_DIGITS = 24;

    public static function parse(string $input): BigDecimal
    {
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\t"], '', trim($input));
        $text = preg_replace('/[%€]$/u', '', $text) ?? $text;
        if ('' === $text) {
            throw new DecimalParseError('Saisissez un nombre.');
        }

        $negative = false;
        $first = mb_substr($text, 0, 1);
        if (\in_array($first, ['-', "\u{2212}", '+'], true)) {
            $negative = '+' !== $first;
            $text = mb_substr($text, 1);
        }
        if (1 !== preg_match('/^[0-9.,]+$/', $text) || 1 !== preg_match('/[0-9]/', $text)) {
            throw new DecimalParseError('Ce nombre n’est pas lisible. Écrivez par exemple 6,90.');
        }

        [$integer, $decimals] = self::split($text);
        if (\strlen($integer.$decimals) > self::MAX_DIGITS) {
            throw new DecimalParseError('Ce nombre est trop long.');
        }
        $value = BigDecimal::of(('' === $integer ? '0' : $integer).('' === $decimals ? '' : '.'.$decimals));

        return $negative ? $value->negated() : $value;
    }

    /** @return array{0: string, 1: string} the integer digits and the decimal digits */
    private static function split(string $text): array
    {
        $lastComma = strrpos($text, ',');
        $lastPoint = strrpos($text, '.');
        if (false === $lastComma && false === $lastPoint) {
            return [$text, ''];
        }
        if (false !== $lastComma && false !== $lastPoint) {
            $decimal = $lastComma > $lastPoint ? ',' : '.';
            $at = (int) strrpos($text, $decimal);

            return [self::grouped(substr($text, 0, $at), ',' === $decimal ? '.' : ','), self::digits(substr($text, $at + 1))];
        }
        $separator = false !== $lastComma ? ',' : '.';
        if (substr_count($text, $separator) > 1) {
            return [self::grouped($text, $separator), ''];
        }
        $at = (int) strrpos($text, $separator);

        return [self::digits(substr($text, 0, $at)), self::digits(substr($text, $at + 1))];
    }

    private static function grouped(string $text, string $separator): string
    {
        $groups = explode($separator, $text);
        $head = array_shift($groups);
        if (1 !== preg_match('/^[0-9]{1,3}$/', $head)) {
            throw new DecimalParseError('Les milliers de ce nombre sont mal séparés.');
        }
        foreach ($groups as $group) {
            if (1 !== preg_match('/^[0-9]{3}$/', $group)) {
                throw new DecimalParseError('Les milliers de ce nombre sont mal séparés.');
            }
        }

        return $head.implode('', $groups);
    }

    private static function digits(string $text): string
    {
        if (1 !== preg_match('/^[0-9]*$/', $text)) {
            throw new DecimalParseError('Ce nombre n’est pas lisible. Écrivez par exemple 6,90.');
        }

        return $text;
    }
}
