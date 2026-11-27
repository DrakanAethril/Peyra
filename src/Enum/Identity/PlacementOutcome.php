<?php

declare(strict_types=1);

namespace App\Enum\Identity;

/**
 * What the placement at account creation made of a student's directory groups (conception § 3 bis,
 * PlacementRule): placed in the one class they designate, or left « à placer » with the reason.
 */
enum PlacementOutcome: string
{
    case Placed = 'placed';
    case NoCurrentYear = 'no_current_year';
    case NoRecognisedGroup = 'no_recognised_group';
    case ContradictoryGroups = 'contradictory_groups';

    /** Why a student is left without a class, as the « À placer » list says it. */
    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placé par son groupe de l’annuaire',
            self::NoCurrentYear => 'Aucune année scolaire courante',
            self::NoRecognisedGroup => 'Aucun groupe associé à une classe',
            self::ContradictoryGroups => 'Groupes désignant plusieurs classes',
        };
    }
}
