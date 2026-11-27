<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Period;

/** An entry EntryRules accepted: its journal and period found, its lines checked. */
final readonly class EntryFields
{
    /** @param list<EntryLineFields> $lines */
    public function __construct(
        public Journal $journal,
        public Period $period,
        public array $lines,
    ) {
    }
}
