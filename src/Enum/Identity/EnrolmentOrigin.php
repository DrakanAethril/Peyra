<?php

declare(strict_types=1);

namespace App\Enum\Identity;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * How a student came to be in a class for a year (conception § 3 bis): placed by their directory
 * group when the account was created, by hand afterwards, or by the « passage de classe ».
 */
enum EnrolmentOrigin: string implements HasBadge
{
    case Directory = 'directory';
    case Manual = 'manual';
    case Promotion = 'promotion';

    public function label(): string
    {
        return match ($this) {
            self::Directory => 'Annuaire',
            self::Manual => 'À la main',
            self::Promotion => 'Passage de classe',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Directory, self::Promotion => BadgeTone::Info,
            self::Manual => BadgeTone::Neutral,
        };
    }
}
