<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * Where a subscription's occurrences go when they are generated (« abonnement », step 1.4c): into
 * the pending work of the entry grid of their journal and month - nothing is in the books until the
 * person saves it with F10 there -, or straight into the brouillard through the Ledger.
 */
enum SubscriptionTarget: string
{
    case Pending = 'pending';
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Saisie en attente',
            self::Draft => 'Brouillard',
        };
    }

    /** What the choice means, under the option. */
    public function hint(): string
    {
        return match ($this) {
            self::Pending => 'L’écriture attend dans la grille de son journal et de son mois : F10 l’enregistre.',
            self::Draft => 'L’écriture est enregistrée au brouillard, à valider avec les autres.',
        };
    }
}
