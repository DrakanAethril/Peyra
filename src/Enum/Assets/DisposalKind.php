<?php

declare(strict_types=1);

namespace App\Enum\Assets;

/**
 * How a fixed asset leaves the company (step 3.4b): sold (« cession », a price and maybe VAT on it) or
 * scrapped (« mise au rebut », no price).
 */
enum DisposalKind: string
{
    case Sale = 'sale';
    case Scrapping = 'scrapping';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Cession',
            self::Scrapping => 'Mise au rebut',
        };
    }

    /** « Cédée le 30/06/2026 », « Mise au rebut le … ». */
    public function done(): string
    {
        return match ($this) {
            self::Sale => 'Cédée',
            self::Scrapping => 'Mise au rebut',
        };
    }
}
