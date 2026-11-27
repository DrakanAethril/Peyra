<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/** The company's VAT regime (fiche société); the declarations follow it at lot 3. */
enum VatRegime: string
{
    case RealNormalMonthly = 'real_normal_monthly';
    case RealNormalQuarterly = 'real_normal_quarterly';
    case RealSimplified = 'real_simplified';
    case Franchise = 'franchise';

    public function label(): string
    {
        return match ($this) {
            self::RealNormalMonthly => 'Réel normal · mensuel',
            self::RealNormalQuarterly => 'Réel normal · trimestriel',
            self::RealSimplified => 'Réel simplifié',
            self::Franchise => 'Franchise en base',
        };
    }
}
