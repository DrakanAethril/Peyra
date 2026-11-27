<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** A period, or an exercise, open to entries or closed (conception § 5: a closed period refuses entries). */
enum PeriodState: string implements HasBadge
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Closed => 'Close',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Open => BadgeTone::Info,
            self::Closed => BadgeTone::Neutral,
        };
    }
}
