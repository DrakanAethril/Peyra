<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a restore point stands (design-ux.md § 4.9): its copy is a background job - « En
 * préparation… » while it runs, then « Prêt », or « En échec » when it stopped, the dossier itself
 * untouched either way.
 */
enum RestorePointState: string implements HasBadge
{
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'En préparation…',
            self::Ready => 'Prêt',
            self::Failed => 'En échec',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Preparing => BadgeTone::Info,
            self::Ready => BadgeTone::Success,
            self::Failed => BadgeTone::Danger,
        };
    }
}
