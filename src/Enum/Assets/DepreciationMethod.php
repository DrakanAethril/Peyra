<?php

declare(strict_types=1);

namespace App\Enum\Assets;

/**
 * How a fixed asset is depreciated (lot 3, step 3.4): not at all (a goodwill presumed of
 * unlimited use, land, a deposit), linearly, by the declining method of art. 39 A CGI, or by the
 * units of work it gives. DepreciationPlan computes each.
 */
enum DepreciationMethod: string
{
    case None = 'none';
    case Linear = 'linear';
    case Declining = 'declining';
    case Units = 'units';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Non amortie',
            self::Linear => 'Linéaire',
            self::Declining => 'Dégressif',
            self::Units => 'Unités d’œuvre',
        };
    }
}
