<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * The type of a journal (« journal d'achats »…): what it records, and whether it has a counterpart
 * account - a bank journal's 512…, a cash journal's 53… -, which the entry screen balances against.
 */
enum JournalType: string
{
    case Purchases = 'purchases';
    case Sales = 'sales';
    case Bank = 'bank';
    case Cash = 'cash';
    case General = 'general';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Purchases => 'Achats',
            self::Sales => 'Ventes',
            self::Bank => 'Banque',
            self::Cash => 'Caisse',
            self::General => 'Opérations diverses',
            self::Opening => 'À-nouveaux',
        };
    }

    /** The nature its counterpart account must have; null when the journal has none. */
    public function counterpartNature(): ?AccountNature
    {
        return match ($this) {
            self::Bank => AccountNature::Bank,
            self::Cash => AccountNature::Cash,
            default => null,
        };
    }
}
