<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Enum\Accounting\JournalType;

/** A journal a FEC names: its code and label, its type read from its lines or its name, a bank or cash journal's counterpart, its first line in the file. */
final readonly class FecImportJournal
{
    public function __construct(
        public string $code,
        public string $label,
        public JournalType $type,
        public ?string $counterpart,
        public int $line,
    ) {
    }
}
