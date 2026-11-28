<?php

declare(strict_types=1);

namespace App\Enum\Banking;

/**
 * What a bill of exchange (« effet », a LCR) was handed to the bank for (step 2.6b): collected at its
 * due date, or discounted at once against the bank's charges. Nothing else of a bill is built
 * (lot 2, « Banque »: creation, remittance for collection, remittance for discount).
 */
enum BillRemittance: string
{
    case Collection = 'collection';
    case Discount = 'discount';

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Remis à l’encaissement',
            self::Discount => 'Remis à l’escompte',
        };
    }
}
