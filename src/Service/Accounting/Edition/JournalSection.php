<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Accounting\Journal;
use App\Service\Accounting\Ledger\Totals;

/** A journal in the edition of the journals: its months, and its total over the window. */
final readonly class JournalSection
{
    /** @param list<JournalMonth> $months */
    public function __construct(
        public Journal $journal,
        public array $months,
        public Totals $total,
    ) {
    }

    public function entryCount(): int
    {
        return array_sum(array_map(static fn (JournalMonth $month): int => \count($month->entries), $this->months));
    }
}
