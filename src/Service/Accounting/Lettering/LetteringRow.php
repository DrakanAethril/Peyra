<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Service\Shared\Money;

/** A line of the list « Comptes à lettrer »: an account (a third party on its collective) and what is left to letter on it. */
final readonly class LetteringRow
{
    public function __construct(
        public LetteringKey $key,
        public int $openLines,
        public int $partialGroups,
        public Money $openDebit,
        public Money $openCredit,
    ) {
    }

    /** What stays unlettered, debit minus credit. */
    public function openBalance(): Money
    {
        return $this->openDebit->minus($this->openCredit);
    }
}
