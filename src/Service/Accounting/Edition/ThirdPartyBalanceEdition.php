<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Ledger\Totals;

/**
 * Comptabilité › Éditions › Balance auxiliaire (lot 1, step 1.5b): every third party named on lines
 * of the exercise up to the window's end - on its collective or on another account of its nature
 * (416000, 419100) -, by code, grouped by kind (customers, suppliers, employees, others) with a
 * subtotal, or one kind alone. A kind's subtotal is what its collectives carry for third parties:
 * the general balance and this one meet there. Runs in the dossier's context.
 */
final readonly class ThirdPartyBalanceEdition
{
    public function __construct(
        private LedgerReader $reader,
        private ThirdPartyRepository $thirdParties,
    ) {
    }

    public function read(Dossier $dossier, EditionWindow $window, EntryScope $scope, ?ThirdPartyKind $kind): BalanceReport
    {
        $before = $window->hasOpening() ? $this->reader->thirdPartyTotals($dossier, $window->exerciseStart(), $window->eve(), $scope) : [];
        $within = $this->reader->thirdPartyTotals($dossier, $window->from, $window->to, $scope);

        $byKind = [];
        foreach (ThirdPartyKind::cases() as $case) {
            if (null === $kind || $kind === $case) {
                $byKind[$case->plural()] = [];
            }
        }
        foreach ($this->thirdParties->findByDossier($dossier) as $thirdParty) {
            $id = (int) $thirdParty->getId();
            if ((!isset($before[$id]) && !isset($within[$id])) || !isset($byKind[$thirdParty->getKind()->plural()])) {
                continue;
            }
            $byKind[$thirdParty->getKind()->plural()][] = BalanceLine::of(
                null,
                $thirdParty,
                ($before[$id] ?? Totals::zero())->balance(),
                $within[$id] ?? Totals::zero(),
            );
        }

        return BalanceReport::of($byKind, $window->hasOpening());
    }
}
