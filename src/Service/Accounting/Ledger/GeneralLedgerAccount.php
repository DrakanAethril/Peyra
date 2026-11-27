<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Account;

/**
 * An account of the general ledger (« grand livre ») over a window: what it carried before the
 * window in the same exercise, its movements in the window, and the total at its end.
 */
final readonly class GeneralLedgerAccount
{
    /** @param list<LedgerMovement> $movements */
    public function __construct(
        public Account $account,
        public Totals $before,
        public array $movements,
        public Totals $total,
    ) {
    }
}
