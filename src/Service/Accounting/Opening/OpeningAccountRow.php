<?php

declare(strict_types=1);

namespace App\Service\Accounting\Opening;

use App\Entity\Accounting\Account;
use App\Service\Shared\Money;

/** An account of the opening balance: what the à-nouveaux carry on it, debit and credit, over how many lines. */
final readonly class OpeningAccountRow
{
    public function __construct(
        public Account $account,
        public int $lines,
        public Money $debit,
        public Money $credit,
    ) {
    }
}
