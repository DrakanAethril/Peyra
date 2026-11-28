<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * The bands of an aged balance (« balance âgée », design-ux.md § 3.2: « tranches d'ancienneté à
 * la date du dossier »): not yet due, then overdue by 1 to 30 days, 31 to 60, 61 to 90, more than
 * 90 - the four bands of the market's tools (Pennylane, Sage), counted from the due date.
 */
enum AgeBand: string
{
    case NotDue = 'not_due';
    case UpTo30 = 'up_to_30';
    case UpTo60 = 'up_to_60';
    case UpTo90 = 'up_to_90';
    case Over90 = 'over_90';

    public function label(): string
    {
        return match ($this) {
            self::NotDue => 'Non échu',
            self::UpTo30 => '1 à 30 jours',
            self::UpTo60 => '31 à 60 jours',
            self::UpTo90 => '61 à 90 jours',
            self::Over90 => 'Plus de 90 jours',
        };
    }

    /** The band of an amount due on `due`, read at `at`: not due until the day is past. */
    public static function of(\DateTimeImmutable $due, \DateTimeImmutable $at): self
    {
        $late = (int) (new \DateTimeImmutable($due->format('Y-m-d')))->diff(new \DateTimeImmutable($at->format('Y-m-d')))->format('%r%a');

        return match (true) {
            $late <= 0 => self::NotDue,
            $late <= 30 => self::UpTo30,
            $late <= 60 => self::UpTo60,
            $late <= 90 => self::UpTo90,
            default => self::Over90,
        };
    }
}
