<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Service\Accounting\Ledger\GeneralLedgerAccount;
use App\Service\Accounting\Ledger\Totals;

/**
 * The general ledger over a window: every account of the selection with lines in the exercise up
 * to the window's end, what it carried the eve (when the window starts after the exercise), its
 * movements with the running balance, its total; then the general total of the selection.
 */
final readonly class GeneralLedgerReport
{
    /** @param list<GeneralLedgerAccount> $accounts */
    public function __construct(
        public array $accounts,
        public Totals $total,
        public bool $hasOpening,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->accounts;
    }

    public function movementCount(): int
    {
        return array_sum(array_map(static fn (GeneralLedgerAccount $account): int => \count($account->movements), $this->accounts));
    }
}
