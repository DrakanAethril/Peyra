<?php

declare(strict_types=1);

namespace App\Enum\Document;

/**
 * What a student reports on a pièce that no field corrects (design-ux.md §§ 3.5 and 4.3,
 * « Signaler une anomalie »): a pièce received twice, a mandatory mention missing, the supplier's
 * own miscalculation, a quantity that does not match, anything else.
 */
enum AnomalyNature: string
{
    case Duplicate = 'duplicate';
    case MissingMention = 'missing_mention';
    case Miscalculation = 'miscalculation';
    case Quantity = 'quantity';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Duplicate => 'Doublon',
            self::MissingMention => 'Mention obligatoire absente',
            self::Miscalculation => 'Erreur de calcul du fournisseur',
            self::Quantity => 'Quantité',
            self::Other => 'Autre',
        };
    }
}
