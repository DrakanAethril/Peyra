<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where an entry stands (conception § 5, design-ux.md A4): in the brouillard it is changed and
 * deleted freely; validated, it is numbered, never changed nor deleted - it is reversed. Both are
 * balanced: an unbalanced entry does not exist.
 */
enum EntryState: string implements HasBadge
{
    case Draft = 'draft';
    case Validated = 'validated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillard',
            self::Validated => 'Validée',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return match ($this) {
            self::Draft => BadgeTone::Warning,
            self::Validated => BadgeTone::Success,
        };
    }
}
