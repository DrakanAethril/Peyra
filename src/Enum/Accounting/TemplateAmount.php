<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * What the amount of an entry template's line is (« formules simples », design-ux.md § 3.2): the
 * amount before VAT, the VAT, the amount VAT included - all three from the amount typed at the call
 * and the rate -, a fixed amount written on the line, what balances the entry, or nothing (the
 * person types it in the grid).
 */
enum TemplateAmount: string
{
    case Net = 'ht';
    case Vat = 'tva';
    case Gross = 'ttc';
    case Fixed = 'fixed';
    case Balance = 'balance';
    case Typed = 'typed';

    public function label(): string
    {
        return match ($this) {
            self::Net => 'HT',
            self::Vat => 'TVA',
            self::Gross => 'TTC',
            self::Fixed => 'Montant fixe',
            self::Balance => 'Solde de l’écriture',
            self::Typed => 'À saisir',
        };
    }

    /** Computed from the amount typed at the call: the template then asks for it. */
    public function needsBase(): bool
    {
        return self::Net === $this || self::Vat === $this || self::Gross === $this;
    }
}
