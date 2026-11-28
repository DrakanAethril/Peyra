<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Accounting\Entry;
use App\Service\Accounting\Ledger\Totals;

/** The edition of the journals over a window: a section per journal, in code order, and the general total. */
final readonly class JournalReport
{
    /** @param list<JournalSection> $journals */
    public function __construct(
        public array $journals,
        public Totals $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->journals;
    }

    public function entryCount(): int
    {
        return array_sum(array_map(static fn (JournalSection $section): int => $section->entryCount(), $this->journals));
    }

    /** @return list<Entry> every entry of the edition, in its order */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->journals as $section) {
            foreach ($section->months as $month) {
                array_push($entries, ...$month->entries);
            }
        }

        return $entries;
    }
}
