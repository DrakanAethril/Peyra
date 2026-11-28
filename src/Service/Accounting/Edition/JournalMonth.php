<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Accounting\Entry;
use App\Service\Accounting\Ledger\Totals;

/** A month of a journal in its edition: its entries in date order, and what they add up to. */
final readonly class JournalMonth
{
    /** @param list<Entry> $entries lines loaded */
    public function __construct(
        public \DateTimeImmutable $month,
        public array $entries,
        public Totals $total,
    ) {
    }
}
