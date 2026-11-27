<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * Where an entry comes from: typed in the journals, proposed by a module through
 * AccountingGateway (sales, purchases, bank, payroll…), the reversal of a validated entry, or the
 * opening balance of an exercise (à-nouveaux).
 */
enum EntryOrigin: string
{
    case Manual = 'manual';
    case Proposal = 'proposal';
    case Reversal = 'reversal';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Saisie',
            self::Proposal => 'Proposée par un module',
            self::Reversal => 'Contrepassation',
            self::Opening => 'À-nouveaux',
        };
    }
}
