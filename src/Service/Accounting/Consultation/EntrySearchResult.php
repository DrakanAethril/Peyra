<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Entry;
use App\Service\Shared\Money;

/** A page of « Recherche d'écritures »: its entries, lines loaded, and the count and amount of the whole filter. */
final readonly class EntrySearchResult
{
    /** @param list<Entry> $entries */
    public function __construct(
        public array $entries,
        public int $total,
        public Money $amount,
    ) {
    }
}
