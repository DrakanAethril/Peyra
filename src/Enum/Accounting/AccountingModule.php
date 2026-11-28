<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * A module that asks the accounting engine for an entry instead of writing one (conception § 5,
 * README of the plan § 8): the pièces, sales, purchases, inventory, bank, fixed assets, payroll, VAT and the
 * year-end work. Each reaches the books through AccountingGateway alone.
 */
enum AccountingModule: string
{
    /** The pièces' processing screen (step 2.2a): the entry of a pièce read. */
    case Documents = 'documents';
    case Sales = 'sales';
    case Purchasing = 'purchasing';
    case Inventory = 'inventory';
    case Banking = 'banking';
    case Assets = 'assets';
    case Payroll = 'payroll';
    case Tax = 'tax';
    case Closing = 'closing';

    public function label(): string
    {
        return match ($this) {
            self::Documents => 'Pièces',
            self::Sales => 'Ventes',
            self::Purchasing => 'Achats',
            self::Inventory => 'Stocks',
            self::Banking => 'Banque',
            self::Assets => 'Immobilisations',
            self::Payroll => 'Paie',
            self::Tax => 'TVA et impôts',
            self::Closing => 'Travaux d’inventaire',
        };
    }
}
