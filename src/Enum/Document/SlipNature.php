<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * What an error the teacher slips into a pièce is (design-ux.md § 3.5, « Glisser une erreur »),
 * deduced from the value it falls on: an account of the proposal, a VAT rate, an amount, a third
 * party. A « doublon » is not one of them: it is the whole pièce arriving twice.
 */
enum SlipNature: string
{
    case Account = 'account';
    case VatRate = 'vat_rate';
    case Amount = 'amount';
    case ThirdParty = 'third_party';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Compte',
            self::VatRate => 'Taux de TVA',
            self::Amount => 'Montant',
            self::ThirdParty => 'Tiers',
        };
    }
}
