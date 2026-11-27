<?php

declare(strict_types=1);

namespace App\Enum\Dossier;

/** How the company's profit is taxed (fiche société, « régime fiscal »): the tax, then the regime of the return. */
enum ProfitTaxRegime: string
{
    case CorporateRealNormal = 'is_real_normal';
    case CorporateRealSimplified = 'is_real_simplified';
    case IncomeRealNormal = 'ir_real_normal';
    case IncomeRealSimplified = 'ir_real_simplified';

    public function label(): string
    {
        return match ($this) {
            self::CorporateRealNormal => 'Impôt sur les sociétés · réel normal',
            self::CorporateRealSimplified => 'Impôt sur les sociétés · réel simplifié',
            self::IncomeRealNormal => 'Impôt sur le revenu · réel normal',
            self::IncomeRealSimplified => 'Impôt sur le revenu · réel simplifié',
        };
    }
}
