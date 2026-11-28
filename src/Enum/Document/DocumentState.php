<?php

declare(strict_types=1);

namespace App\Enum\Document;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a pièce stands in the inbox (lot 2, step 2.1): arrived and not yet looked at, being
 * worked, posted (its entry is in the books), or set aside with a reason. A deposit arrives « à
 * traiter »; the processing screen (step 2.2) moves it on.
 */
enum DocumentState: string implements HasBadge
{
    case ToProcess = 'to_process';
    case InProgress = 'in_progress';
    case Posted = 'posted';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::ToProcess => 'À traiter',
            self::InProgress => 'En cours',
            self::Posted => 'Comptabilisée',
            self::Discarded => 'Écartée',
        };
    }

    /** The inbox's filter chip. */
    public function plural(): string
    {
        return match ($this) {
            self::ToProcess => 'À traiter',
            self::InProgress => 'En cours',
            self::Posted => 'Comptabilisées',
            self::Discarded => 'Écartées',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::ToProcess => BadgeTone::Info,
            self::InProgress => BadgeTone::Warning,
            self::Posted => BadgeTone::Success,
            self::Discarded => BadgeTone::Neutral,
        };
    }

    /** Its read data may still be corrected: neither posted nor set aside. */
    public function isOpen(): bool
    {
        return self::ToProcess === $this || self::InProgress === $this;
    }
}
