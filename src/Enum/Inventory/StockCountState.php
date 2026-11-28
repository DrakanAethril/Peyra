<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * Where a physical inventory stands (step 2.5b): its counts are typed and saved at will while it is
 * in progress; validated, its gaps have become adjustments and it never changes again.
 */
enum StockCountState: string implements HasBadge
{
    case Draft = 'draft';
    case Validated = 'validated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'En cours',
            self::Validated => 'Validé',
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
