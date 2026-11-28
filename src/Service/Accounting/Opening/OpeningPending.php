<?php

declare(strict_types=1);

namespace App\Service\Accounting\Opening;

use App\Entity\Identity\User;
use App\Service\Shared\Money;

/** A person's à-nouveaux typed in the grid and not yet in the brouillard: what they add up to and their gap. */
final readonly class OpeningPending
{
    public function __construct(
        public User $author,
        public int $entries,
        public int $lines,
        public Money $debit,
        public Money $credit,
    ) {
    }

    /** Debit minus credit: what the typing still lacks to balance. */
    public function gap(): Money
    {
        return $this->debit->minus($this->credit);
    }
}
