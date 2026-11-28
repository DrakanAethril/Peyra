<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/** The headings of the clarified payslip (« bulletin clarifié »), as maquette 4 draws them. */
enum PayslipSection: string
{
    case Health = 'health';
    case WorkAccident = 'work_accident';
    case Retirement = 'retirement';
    case FamilyUnemploymentOther = 'family_unemployment_other';
    case Csg = 'csg';
    case Reductions = 'reductions';

    public function label(): string
    {
        return match ($this) {
            self::Health => 'Santé',
            self::WorkAccident => 'Accidents du travail, maladies professionnelles',
            self::Retirement => 'Retraite',
            self::FamilyUnemploymentOther => 'Famille, chômage, autres contributions',
            self::Csg => 'CSG et CRDS',
            self::Reductions => 'Exonérations et allègements',
        };
    }
}
