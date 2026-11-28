<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where an employee stands at the dossier's date: in the company, hired on a later day, or gone after their last day (step 4.2c). */
enum EmployeeState: string implements HasBadge
{
    case Present = 'present';
    case Upcoming = 'upcoming';
    case Departed = 'departed';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Upcoming => 'Entrée à venir',
            self::Departed => 'Sorti',
        };
    }

    /** The plural, for the filters of the list. */
    public function plural(): string
    {
        return match ($this) {
            self::Present => 'Présents',
            self::Upcoming => 'À venir',
            self::Departed => 'Sortis',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return self::Upcoming === $this ? BadgeTone::Info : BadgeTone::Neutral;
    }
}
