<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Repository\Accounting\AccountRepository;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Accounting\Ledger\Totals;

/**
 * Comptabilité › Éditions › Balance (« balance générale », lot 1, step 1.5b): every account of the
 * selection that carries lines in the exercise up to the window's end, in number order, grouped by
 * class with a subtotal (« Total classe 4 »), and the general total - whose debit and credit
 * balances are equal when the whole plan is read. Runs in the dossier's context.
 */
final readonly class TrialBalanceEdition
{
    public function __construct(
        private LedgerReader $reader,
        private AccountRepository $accounts,
        private ReferenceCharts $charts,
    ) {
    }

    public function read(Dossier $dossier, EditionWindow $window, EntryScope $scope, AccountRange $range): BalanceReport
    {
        $before = $window->hasOpening() ? $this->reader->accountTotals($dossier, $window->exerciseStart(), $window->eve(), $scope) : [];
        $within = $this->reader->accountTotals($dossier, $window->from, $window->to, $scope);
        $chart = $this->charts->get((string) $dossier->getChartCode()) ?? $this->charts->all()[0];

        $byClass = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $id = (int) $account->getId();
            if ((!isset($before[$id]) && !isset($within[$id])) || !$range->contains($account->getNumber())) {
                continue;
            }
            $digit = $account->getClassDigit();
            $byClass[\sprintf('Classe %d · %s', $digit, $chart->classLabel($digit))][] = BalanceLine::of(
                $account,
                null,
                ($before[$id] ?? Totals::zero())->balance(),
                $within[$id] ?? Totals::zero(),
            );
        }

        return BalanceReport::of($byClass, $window->hasOpening());
    }
}
