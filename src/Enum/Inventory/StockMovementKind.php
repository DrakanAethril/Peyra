<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * What a stock movement does (step 2.5): an entry brings a quantity in at a cost (a receipt, a
 * production, the opening stock), an exit takes it out at the value of the article's method (a
 * delivery, a consumption, a scrapping), a transfer moves it from one storage place to another
 * without changing its value, an adjustment corrects the quantity up or down.
 */
enum StockMovementKind: string implements HasBadge
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrée',
            self::Exit => 'Sortie',
            self::Transfer => 'Transfert',
            self::Adjustment => 'Ajustement',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Entry => BadgeTone::Info,
            self::Exit => BadgeTone::Neutral,
            self::Transfer => BadgeTone::Neutral,
            self::Adjustment => BadgeTone::Warning,
        };
    }
}
