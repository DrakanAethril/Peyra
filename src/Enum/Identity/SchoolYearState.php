<?php

declare(strict_types=1);

namespace App\Enum\Identity;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a school year stands against the current one, which an administrator chooses: the years
 * before it are history (their classes are read, never changed), the ones after it are prepared.
 */
enum SchoolYearState: string implements HasBadge
{
    case Current = 'current';
    case Past = 'past';
    case Upcoming = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Courante',
            self::Past => 'Passée',
            self::Upcoming => 'À venir',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Current => BadgeTone::Info,
            self::Past, self::Upcoming => BadgeTone::Neutral,
        };
    }
}
