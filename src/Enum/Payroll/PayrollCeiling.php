<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * The « plafonnement » of a contribution on the gross salary: the part of it the rate applies to,
 * counted in monthly ceilings of the social security (PMSS) - with the annual regularisation of
 * step 4.3.
 */
enum PayrollCeiling: string
{
    case None = 'none';
    case Tranche1 = 'tranche_1';
    case Tranche2 = 'tranche_2';
    case Tranches1And2 = 'tranches_1_2';
    case FourCeilings = 'four_ceilings';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sans plafond',
            self::Tranche1 => 'Tranche 1 : jusqu’au plafond',
            self::Tranche2 => 'Tranche 2 : de 1 à 8 plafonds',
            self::Tranches1And2 => 'Tranches 1 et 2 : jusqu’à 8 plafonds',
            self::FourCeilings => 'Jusqu’à 4 plafonds',
        };
    }

    /** The short form of the list (« T1 », « 4 PSS »), empty without a ceiling. */
    public function short(): string
    {
        return match ($this) {
            self::None => '',
            self::Tranche1 => 'T1',
            self::Tranche2 => 'T2',
            self::Tranches1And2 => 'T1 + T2',
            self::FourCeilings => '4 PSS',
        };
    }
}
