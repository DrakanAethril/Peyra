<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

/** An entry read from a FEC (JournalCode + EcritureNum): the journal's code, the date, the pièce, the label of its first line, its lines carrying an amount. */
final readonly class FecImportEntry
{
    /** @param list<FecImportLine> $lines */
    public function __construct(
        public string $journal,
        public \DateTimeImmutable $date,
        public string $document,
        public string $label,
        public array $lines,
        public int $line,
    ) {
    }
}
