<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where the background import of a FEC into a new template stands (step 1.10b): passes still to
 * run, done, or stopped by an error - « Relancer » takes it up again where it stopped.
 */
enum FecImportState: string implements HasBadge
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cours…',
            self::Done => 'Terminé',
            self::Failed => 'Arrêté',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Pending => BadgeTone::Info,
            self::Done => BadgeTone::Success,
            self::Failed => BadgeTone::Danger,
        };
    }
}
