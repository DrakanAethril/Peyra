<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

/**
 * A line of an account as the lettering rules read it: primitives only (LetteringRules and
 * LetteringProposals are pure). Amounts are whole cents; the day is ISO.
 */
final readonly class LetteringLine
{
    public function __construct(
        public int $id,
        public int $debit,
        public int $credit,
        public ?string $letter = null,
        public string $day = '',
        public string $document = '',
    ) {
    }

    /** Debit minus credit. */
    public function amount(): int
    {
        return $this->debit - $this->credit;
    }
}
