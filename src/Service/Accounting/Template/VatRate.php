<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * A VAT rate in percent as a person types it in a template or at a call (« 20 », « 5,5 », « 2.1 »):
 * an exact decimal from 0 to 100, three decimals at most - never a float. A template keeps it as
 * text, its trailing zeros dropped (« 20 », « 5.5 »). No rate is written in the code: the
 * template's author writes the one its entries take (the regulatory rates arrive at lot 3).
 */
final class VatRate
{
    /** The rate, or null when the text is not one. */
    public static function parse(string $typed): ?BigDecimal
    {
        $text = str_replace([',', ' ', "\u{202F}", "\u{00A0}", '%'], ['.', '', '', '', ''], trim($typed));
        if (1 !== preg_match('/^\d{1,3}(\.\d{1,3})?$/', $text)) {
            return null;
        }
        try {
            $rate = BigDecimal::of($text);
        } catch (MathException) {
            return null;
        }

        return $rate->isGreaterThan(100) ? null : $rate;
    }

    /** « 20 », « 5.5 »: as a template stores it. */
    public static function canonical(BigDecimal $rate): string
    {
        return (string) $rate->strippedOfTrailingZeros();
    }
}
