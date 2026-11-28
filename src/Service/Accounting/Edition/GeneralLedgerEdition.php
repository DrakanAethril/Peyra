<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\GeneralLedgerAccount;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Ledger\Totals;

/**
 * Comptabilité › Éditions › Grand livre (lot 1, step 1.5b): LedgerReader's general ledger, narrowed
 * to the accounts of the selection. Runs in the dossier's context.
 */
final readonly class GeneralLedgerEdition
{
    public function __construct(
        private LedgerReader $reader,
    ) {
    }

    public function read(Dossier $dossier, EditionWindow $window, EntryScope $scope, AccountRange $range): GeneralLedgerReport
    {
        $accounts = array_values(array_filter(
            $this->reader->generalLedger($dossier, $window->from, $window->to, $scope),
            static fn (GeneralLedgerAccount $account): bool => $range->contains($account->account->getNumber()),
        ));
        $total = Totals::zero();
        foreach ($accounts as $account) {
            $total = $total->plus($account->total);
        }

        return new GeneralLedgerReport($accounts, $total, $window->hasOpening());
    }
}
