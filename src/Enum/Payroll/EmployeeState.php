<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where an employee stands at the dossier's date: in the company, or hired on a later day. */
enum EmployeeState: string implements HasBadge
{
    case Present = 'present';
    case Upcoming = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Upcoming => 'Entrée à venir',
        };
    }

    /** The plural, for the filters of the list. */
    public function plural(): string
    {
        return match ($this) {
            self::Present => 'Présents',
            self::Upcoming => 'À venir',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return self::Present === $this ? BadgeTone::Neutral : BadgeTone::Info;
    }
}
