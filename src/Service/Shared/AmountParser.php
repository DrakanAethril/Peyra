<?php

declare(strict_types=1);

namespace App\Service\Shared;

/**
 * Reads an amount typed by a person (« 1 234,56 », « 1234.56 », « 1234 », « −138 », « 2 622,00 € »)
 * into a Money, or refuses it with a sentence the screen can print under the field.
 *
 * The rules are the amount field's (design-ui.md § 4.5), so a value the browser formatted and a
 * value pasted from a spreadsheet read the same way:
 * - spaces of any kind (U+0020, U+00A0, U+202F, U+2009) and a trailing « € » are ignored;
 * - one sign at the head: « - », « − » (U+2212) or « + »;
 * - a lone comma or a lone point is the decimal separator (« le point devient virgule »);
 * - when both appear, the last one is the decimal separator and the other separates thousands,
 *   in groups of three (« 1.234,56 », « 1,234.56 »); a separator repeated alone does the same
 *   (« 1.234.567 »);
 * - two decimals at most: a third is never rounded away silently.
 *
 * Arithmetic (« 2760-138 ») is the field's own comfort, evaluated in the browser before the cents
 * are posted (amount_input_controller.js); a server reading never computes.
 */
final class AmountParser
{
    /** Up to 999 999 999 999,99: far above any amount of a teaching dossier, far below PHP_INT_MAX. */
    private const int MAX_INTEGER_DIGITS = 12;

    public static function parse(string $input): Money
    {
        $text = str_replace([' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\t"], '', trim($input));
        $text = preg_replace('/€$/u', '', $text) ?? $text;
        if ('' === $text) {
            throw new AmountParseError('Saisissez un montant.');
        }

        $negative = false;
        $first = mb_substr($text, 0, 1);
        if (\in_array($first, ['-', "\u{2212}", '+'], true)) {
            $negative = '+' !== $first;
            $text = mb_substr($text, 1);
        }

        if (1 !== preg_match('/^[0-9.,]+$/', $text) || 1 !== preg_match('/[0-9]/', $text)) {
            throw new AmountParseError('Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.');
        }

        [$integer, $decimals] = self::split($text);
        $integer = ltrim($integer, '0');
        if (\strlen($decimals) > 2) {
            throw new AmountParseError('Un montant a deux décimales au plus.');
        }
        if (\strlen($integer) > self::MAX_INTEGER_DIGITS) {
            throw new AmountParseError('Ce montant est trop grand.');
        }

        $cents = (int) ('' === $integer ? '0' : $integer) * 100 + (int) str_pad($decimals, 2, '0');

        return Money::cents($negative ? -$cents : $cents);
    }

    /** Null for a field left empty, where empty means « not given » rather than zero. */
    public static function parseOptional(string $input): ?Money
    {
        $blank = str_replace([' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\t"], '', $input);

        return '' === $blank ? null : self::parse($input);
    }

    /**
     * The integer digits and the decimal digits of a text made of digits, commas and points.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $text): array
    {
        $lastComma = strrpos($text, ',');
        $lastPoint = strrpos($text, '.');

        if (false === $lastComma && false === $lastPoint) {
            return [$text, ''];
        }

        if (false !== $lastComma && false !== $lastPoint) {
            $decimal = $lastComma > $lastPoint ? ',' : '.';
            $thousands = ',' === $decimal ? '.' : ',';
            $at = (int) strrpos($text, $decimal);

            return [self::grouped(substr($text, 0, $at), $thousands), self::digits(substr($text, $at + 1))];
        }

        $separator = false !== $lastComma ? ',' : '.';
        if (substr_count($text, $separator) > 1) {
            return [self::grouped($text, $separator), ''];
        }
        $at = (int) strrpos($text, $separator);

        return [self::digits(substr($text, 0, $at)), self::digits(substr($text, $at + 1))];
    }

    /** « 1.234.567 » with its separator: groups of three after the first, or the text is refused. */
    private static function grouped(string $text, string $separator): string
    {
        $groups = explode($separator, $text);
        $head = array_shift($groups);
        if (1 !== preg_match('/^[0-9]{1,3}$/', $head)) {
            throw new AmountParseError('Les milliers de ce montant sont mal séparés.');
        }
        foreach ($groups as $group) {
            if (1 !== preg_match('/^[0-9]{3}$/', $group)) {
                throw new AmountParseError('Les milliers de ce montant sont mal séparés.');
            }
        }

        return $head.implode('', $groups);
    }

    private static function digits(string $text): string
    {
        if (1 !== preg_match('/^[0-9]*$/', $text)) {
            throw new AmountParseError('Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.');
        }

        return $text;
    }
}
