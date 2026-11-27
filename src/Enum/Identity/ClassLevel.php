<?php

declare(strict_types=1);

namespace App\Enum\Identity;

/** The year of the BTS a class prepares (conception § 3 bis): first or second. */
enum ClassLevel: int
{
    case First = 1;
    case Second = 2;

    public function label(): string
    {
        return match ($this) {
            self::First => '1re année',
            self::Second => '2e année',
        };
    }
}
