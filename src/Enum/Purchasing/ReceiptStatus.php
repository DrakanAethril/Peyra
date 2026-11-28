<?php

declare(strict_types=1);

namespace App\Enum\Purchasing;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where a validated supplier order stands against its receipts (step 2.4): read, never stored. */
enum ReceiptStatus: string implements HasBadge
{
    case Waiting = 'waiting';
    case Partial = 'partial';
    case Received = 'received';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'À recevoir',
            self::Partial => 'Reçue en partie',
            self::Received => 'Reçue',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Waiting => BadgeTone::Neutral,
            self::Partial => BadgeTone::Warning,
            self::Received => BadgeTone::Success,
        };
    }
}
