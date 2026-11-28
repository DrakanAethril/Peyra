<?php

declare(strict_types=1);

namespace App\Enum\Assets;

/**
 * How a fixed asset entered the company (step 3.4): bought - its cost is the price before VAT plus
 * the incidental costs -, or produced by the company itself (« production immobilisée »: its cost
 * of production, credited to 72 when it is entered).
 */
enum AssetOrigin: string
{
    case Purchase = 'purchase';
    case Production = 'production';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Acquisition',
            self::Production => 'Production immobilisée',
        };
    }
}
