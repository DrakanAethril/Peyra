<?php

declare(strict_types=1);

namespace App\Enum\ThirdParty;

/** How a third party usually pays or is paid (« mode de règlement »). */
enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cheque = 'cheque';
    case Card = 'card';
    case DirectDebit = 'direct_debit';
    case Cash = 'cash';
    case Bill = 'bill';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Virement',
            self::Cheque => 'Chèque',
            self::Card => 'Carte bancaire',
            self::DirectDebit => 'Prélèvement',
            self::Cash => 'Espèces',
            self::Bill => 'Effet de commerce (LCR)',
        };
    }

    /** The word the CSV writes and reads back. */
    public function csvValue(): string
    {
        return match ($this) {
            self::Transfer => 'virement',
            self::Cheque => 'cheque',
            self::Card => 'carte',
            self::DirectDebit => 'prelevement',
            self::Cash => 'especes',
            self::Bill => 'effet',
        };
    }

    public static function fromCsv(string $folded): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->csvValue() === $folded) {
                return $case;
            }
        }

        return match ($folded) {
            'cb', 'carte bancaire' => self::Card,
            'lcr', 'effet de commerce', 'traite' => self::Bill,
            default => null,
        };
    }
}
