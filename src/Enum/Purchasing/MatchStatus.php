<?php

declare(strict_types=1);

namespace App\Enum\Purchasing;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where a supplier's pièce stands against its order and receipts (step 2.4, InvoiceMatch): read, never stored. */
enum MatchStatus: string implements HasBadge
{
    case NoOrder = 'no_order';
    case UnknownOrder = 'unknown_order';
    case NotReceived = 'not_received';
    case Gap = 'gap';
    case Matched = 'matched';

    public function label(): string
    {
        return match ($this) {
            self::NoOrder => 'Sans commande',
            self::UnknownOrder => 'Commande introuvable',
            self::NotReceived => 'Non reçue',
            self::Gap => 'Écart',
            self::Matched => 'Rapprochée',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::NoOrder => BadgeTone::Neutral,
            self::UnknownOrder => BadgeTone::Danger,
            self::NotReceived, self::Gap => BadgeTone::Warning,
            self::Matched => BadgeTone::Success,
        };
    }
}
