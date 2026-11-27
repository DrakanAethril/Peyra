<?php

declare(strict_types=1);

namespace App\Enum\ThirdParty;

/**
 * How a third party's payment term is reckoned from the invoice date (« conditions de
 * règlement »), the four shapes French invoices carry plus « comptant ». The due date itself is
 * PaymentTerms::dueDate(); the schedule of step 2.7 reads it.
 */
enum PaymentTermKind: string
{
    /** Due on the invoice date: « comptant », « à réception ». */
    case Immediate = 'immediate';
    /** « 30 jours date de facture »: the date plus N days. */
    case Net = 'net';
    /** « 30 jours fin de mois »: the date plus N days, carried to the end of that month. */
    case NetEndOfMonth = 'net_end_of_month';
    /** « Fin de mois + 45 jours »: the end of the invoice's month, plus N days. */
    case EndOfMonthNet = 'end_of_month_net';
    /** « Le 10 du mois suivant »: day D of the month after the invoice's. */
    case DayOfNextMonth = 'day_of_next_month';

    public function label(): string
    {
        return match ($this) {
            self::Immediate => 'Comptant (à réception)',
            self::Net => 'N jours date de facture',
            self::NetEndOfMonth => 'N jours fin de mois',
            self::EndOfMonthNet => 'Fin de mois + N jours',
            self::DayOfNextMonth => 'Le J du mois suivant',
        };
    }

    public function needsDays(): bool
    {
        return \in_array($this, [self::Net, self::NetEndOfMonth, self::EndOfMonthNet], true);
    }

    public function needsDayOfMonth(): bool
    {
        return self::DayOfNextMonth === $this;
    }

    /** The word the CSV writes and reads back. */
    public function csvValue(): string
    {
        return match ($this) {
            self::Immediate => 'comptant',
            self::Net => 'date_de_facture',
            self::NetEndOfMonth => 'fin_de_mois',
            self::EndOfMonthNet => 'fin_de_mois_plus',
            self::DayOfNextMonth => 'mois_suivant',
        };
    }

    public static function fromCsv(string $folded): ?self
    {
        foreach (self::cases() as $case) {
            if (str_replace('_', ' ', $case->csvValue()) === $folded) {
                return $case;
            }
        }

        return match ($folded) {
            'a reception', 'reception' => self::Immediate,
            default => null,
        };
    }
}
