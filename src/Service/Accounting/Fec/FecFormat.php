<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

use App\Service\Shared\Money;

/**
 * The FEC's format (Livre des procédures fiscales, article A47 A-1, read on Légifrance on
 * 2026-11-28): eighteen zones in this order, Debit and Credit replaceable by Montant and Sens; days
 * « AAAAMMJJ »; amounts with a decimal comma and no thousands separator; the file named
 * « SirenFECAAAAMMJJ » after the exercise's closing day.
 */
final class FecFormat
{
    /** The eighteen zones, in the order the article lists them. */
    public const array COLUMNS = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
        'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
        'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
    ];

    /** The same zones with Montant and Sens in place of Debit and Credit (A47 A-1, VII.2°). */
    public const array SIGNED_COLUMNS = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
        'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Montant', 'Sens',
        'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
    ];

    /** The zones no line leaves empty (Debit and Credit, or Montant and Sens, apart). */
    public const array REQUIRED = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
        'PieceRef', 'PieceDate', 'EcritureLib', 'ValidDate',
    ];

    /** « 20260131 » */
    public static function day(\DateTimeImmutable $day): string
    {
        return $day->format('Ymd');
    }

    /** « 20260131 » read back, or null for anything that is not a real day. */
    public static function parseDay(string $text): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{8}$/', $text)) {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Ymd', $text);

        return false === $day || $day->format('Ymd') !== $text ? null : $day;
    }

    /** « 1234,56 », « 0,00 », « -138,00 »: a decimal comma, no thousands separator. */
    public static function amount(Money $amount): string
    {
        $cents = abs($amount->cents);

        return ($amount->isNegative() ? '-' : '').intdiv($cents, 100).','.str_pad((string) ($cents % 100), 2, '0', \STR_PAD_LEFT);
    }

    /**
     * An amount of the file read back - « 1234,56 », « 1234 », « -12,5 », « 12,50- » (a sign may
     * close the figure) -, or null when it is not one: a decimal point or a thousands separator
     * is not the FEC's format.
     */
    public static function parseAmount(string $text): ?Money
    {
        if (1 !== preg_match('/^([+-]?)(\d{1,13})(?:,(\d{1,2}))?([+-]?)$/', $text, $parts) || ('' !== $parts[1] && '' !== $parts[4])) {
            return null;
        }
        $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3], 2, '0');

        return Money::cents('-' === $parts[1] || '-' === $parts[4] ? -$cents : $cents);
    }

    /** « 512345678FEC20261231.txt » for a SIREN and a closing day. */
    public static function fileName(string $siren, \DateTimeImmutable $closing): string
    {
        return $siren.'FEC'.self::day($closing).'.txt';
    }
}
