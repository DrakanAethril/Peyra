<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\EntryLine;
use App\Service\Shared\Money;

/** A line of an account's movements, with the balance after it (debit minus credit). */
final readonly class LedgerMovement
{
    public function __construct(
        public EntryLine $line,
        public Money $balanceAfter,
    ) {
    }
}
