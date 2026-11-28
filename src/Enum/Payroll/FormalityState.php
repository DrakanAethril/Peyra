<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where a hiring formality stands at the dossier's date (step 4.2b, HiringChecklist). */
enum FormalityState: string implements HasBadge
{
    case Done = 'done';
    /** Done, but after its delay. */
    case Late = 'late';
    case ToDo = 'to_do';
    /** Not done, and its delay has passed. */
    case Overdue = 'overdue';
    /** The employee was hired before the dossier's first exercise: their formalities are not the dossier's. */
    case NotFollowed = 'not_followed';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Fait',
            self::Late => 'Hors délai',
            self::ToDo => 'À faire',
            self::Overdue => 'En retard',
            self::NotFollowed => 'Avant le dossier',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Done => BadgeTone::Success,
            self::Late, self::ToDo => BadgeTone::Warning,
            self::Overdue => BadgeTone::Danger,
            self::NotFollowed => BadgeTone::Neutral,
        };
    }

    /** Still to be done. */
    public function isOpen(): bool
    {
        return self::ToDo === $this || self::Overdue === $this;
    }
}
