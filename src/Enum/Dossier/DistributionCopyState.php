<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where one dossier of a distribution stands (design-ux.md § 4.2, step 3): waiting for its turn,
 * being copied, ready, or failed - « Relancer » puts a failed one back in the queue.
 */
enum DistributionCopyState: string implements HasBadge
{
    case Waiting = 'waiting';
    case Copying = 'copying';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'En attente',
            self::Copying => 'En copie…',
            self::Ready => 'Prêt',
            self::Failed => 'En échec',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Waiting => BadgeTone::Neutral,
            self::Copying => BadgeTone::Info,
            self::Ready => BadgeTone::Success,
            self::Failed => BadgeTone::Danger,
        };
    }

    /** Still to be made: waiting, or left half way by a stopped worker. */
    public function isPending(): bool
    {
        return self::Waiting === $this || self::Copying === $this;
    }
}
