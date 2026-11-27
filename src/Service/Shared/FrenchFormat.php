<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Numbers and dates as Peyra prints them (design-ux.md § 6.6), behind the Twig filters of
 * App\Twig\FormatExtension. Pure: no locale of the server, no ICU data whose abbreviations change
 * from one version to the next, no clock.
 *
 * Spaces: U+202F (narrow no-break) between thousands, as the design asks and as the fonts carry it
 * (Inter and IBM Plex Mono's Pi file, step 0.3); U+00A0 (no-break) before a unit (« € », « % »,
 * « h », the C or D of a balance) and after the minus sign of « − 138,00 » (U+2212).
 */
final class FrenchFormat
{
    public const string THIN = "\u{202F}";
    public const string NBSP = "\u{00A0}";
    public const string MINUS = "\u{2212}";

    private const array MONTHS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    private const array SHORT_MONTHS = [
        1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
        'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
    ];

    /** « 3 248,40 », « − 138,00 », « 0,00 »: two decimals always, no currency sign. */
    public static function amount(Money $amount): string
    {
        $cents = abs($amount->cents);
        $text = self::grouped(intdiv($cents, 100)).','.str_pad((string) ($cents % 100), 2, '0', \STR_PAD_LEFT);

        return $amount->isNegative() ? self::MINUS.self::NBSP.$text : $text;
    }

    /** A debit or credit cell: empty for zero, which these columns never print. */
    public static function amountCell(Money $amount): string
    {
        return $amount->isZero() ? '' : self::amount($amount);
    }

    /** « 3 248,40 € », for an amount inside a sentence. */
    public static function amountInEuros(Money $amount): string
    {
        return self::amount($amount).self::NBSP.'€';
    }

    /**
     * A balance read as debit minus credit: « 3 248,40 D » when positive, « 3 248,40 C » when
     * negative, « 0,00 » when settled.
     */
    public static function balance(Money $debitMinusCredit): string
    {
        if ($debitMinusCredit->isZero()) {
            return self::amount($debitMinusCredit);
        }

        return self::amount($debitMinusCredit->abs()).self::NBSP.($debitMinusCredit->isPositive() ? 'D' : 'C');
    }

    /** Whole euros of a declaration, already rounded by its rule: « 3 956 », « − 12 ». */
    public static function wholeEuros(int $euros): string
    {
        $text = self::grouped(abs($euros));

        return $euros < 0 ? self::MINUS.self::NBSP.$text : $text;
    }

    /** « 20 % », « 1,646 % »: the decimals the rate has, trailing zeros dropped. */
    public static function percent(BigNumber|int|string $rate): string
    {
        return self::decimal(BigDecimal::of($rate)->strippedOfTrailingZeros()).self::NBSP.'%';
    }

    /** « 48 060 », « 0,3193 », « 6,9 »: a number with the decimals it has, trailing zeros dropped. */
    public static function number(BigNumber|int|string $value): string
    {
        return self::decimal(BigDecimal::of($value)->strippedOfTrailingZeros());
    }

    /** « 2,40 »: a quantity at the scale the screen asks for (two decimals by default). */
    public static function quantity(BigNumber|int|string $quantity, int $scale = 2): string
    {
        return self::decimal(BigDecimal::of($quantity)->toScale($scale, RoundingMode::HalfUp));
    }

    /** « 151,67 h »: payroll hours, two decimals. */
    public static function hours(BigNumber|int|string $hours): string
    {
        return self::quantity($hours).self::NBSP.'h';
    }

    /** « 12/11/2026 », in grids and fields. */
    public static function date(\DateTimeInterface $date): string
    {
        return $date->format('d/m/Y');
    }

    /** « 12/11 » when the year is the one of the fiscal year shown, « 12/11/2026 » otherwise. */
    public static function dayMonth(\DateTimeInterface $date, int $shownYear): string
    {
        return (int) $date->format('Y') === $shownYear ? $date->format('d/m') : self::date($date);
    }

    /** « 30 nov. 2026 », in chips. */
    public static function chipDate(\DateTimeInterface $date): string
    {
        return $date->format('j').' '.self::SHORT_MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }

    /** « novembre 2026 », a period. */
    public static function period(\DateTimeInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n')].' '.$date->format('Y');
    }

    /** « 10 h 42 », a real time of day. */
    public static function clock(\DateTimeInterface $time): string
    {
        return $time->format('G').self::NBSP.'h'.self::NBSP.$time->format('i');
    }

    /** « 35 min », « 1 h 42 », « 2 h »: a duration given in minutes. */
    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) {
            return $minutes.self::NBSP.'min';
        }
        $hours = intdiv($minutes, 60).self::NBSP.'h';
        $rest = $minutes % 60;

        return 0 === $rest ? $hours : $hours.self::NBSP.str_pad((string) $rest, 2, '0', \STR_PAD_LEFT);
    }

    private static function decimal(BigDecimal $value): string
    {
        $negative = $value->isNegative();
        $parts = explode('.', $value->abs()->toString());
        $text = self::grouped((int) $parts[0]).(isset($parts[1]) ? ','.$parts[1] : '');

        return $negative ? self::MINUS.self::NBSP.$text : $text;
    }

    private static function grouped(int $whole): string
    {
        return number_format($whole, 0, '', self::THIN);
    }
}
