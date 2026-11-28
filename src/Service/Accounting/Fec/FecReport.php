<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec;

use App\Service\Shared\Money;

/**
 * What FecConformity found in a FEC: its faults, in the order of the file, and what it counted -
 * the lines and entries read, the debits and credits added. A file without a fault is conform.
 */
final readonly class FecReport
{
    /** @param list<FecFinding> $findings */
    public function __construct(
        public array $findings,
        public int $lines,
        public int $entries,
        public Money $debit,
        public Money $credit,
        public ?string $encoding = null,
        public ?string $separator = null,
    ) {
    }

    public function isConform(): bool
    {
        return [] === $this->findings;
    }
}
