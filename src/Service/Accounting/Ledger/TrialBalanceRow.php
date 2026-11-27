<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Account;

/** A line of the trial balance (« balance générale »): an account and what its lines add up to. */
final readonly class TrialBalanceRow
{
    public function __construct(
        public Account $account,
        public Totals $totals,
    ) {
    }
}
