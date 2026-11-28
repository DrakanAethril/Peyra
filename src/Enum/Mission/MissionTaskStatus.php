<?php

declare(strict_types=1);

namespace App\Enum\Mission;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/** Where a task of a mission stands for the student (design-ux.md A11): done, current, to do - never right or wrong. */
enum MissionTaskStatus: string implements HasBadge
{
    case Done = 'done';
    case Current = 'current';
    case ToDo = 'to_do';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Faite',
            self::Current => 'En cours',
            self::ToDo => 'À faire',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Done => BadgeTone::Success,
            self::Current => BadgeTone::Info,
            self::ToDo => BadgeTone::Neutral,
        };
    }
}
