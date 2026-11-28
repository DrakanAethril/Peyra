<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;
use App\Service\Shared\Money;

/**
 * Comptabilité › Éditions › Balance âgée (lot 1, step 1.5b, prepared here and completed by lot 2's
 * follow-up of customers and suppliers): at a day - the dossier's date by default -, what each
 * third party of a kind owes or is owed, split by age (AgedBalance), from every line naming it
 * since its exercise opened. Customers read a debit as owed to the company, the other kinds a
 * credit as owed by it. Runs in the dossier's context.
 */
final readonly class AgedBalanceEdition
{
    public function __construct(
        private LedgerReader $reader,
        private ThirdPartyRepository $thirdParties,
    ) {
    }

    public function read(Dossier $dossier, EditionWindow $window, EntryScope $scope, ThirdPartyKind $kind): AgedBalanceReport
    {
        $lines = $this->reader->linesByThirdParty($dossier, $window->exerciseStart(), $window->to, $scope);
        $direction = ThirdPartyKind::Customer === $kind ? 1 : -1;

        $rows = [];
        $total = AgedBalanceRow::total();
        foreach ($this->thirdParties->findByDossier($dossier) as $thirdParty) {
            $own = $lines[(int) $thirdParty->getId()] ?? [];
            if ($kind !== $thirdParty->getKind() || [] === $own) {
                continue;
            }
            $bands = AgedBalance::age(array_map(static fn (EntryLine $line): array => [
                'amount' => $line->getDebit()->minus($line->getCredit()),
                'day' => $line->getEntry()->getEntryDate(),
                'due' => $line->getDueDate(),
            ], $own), $window->to);
            $bands = array_map(static fn (Money $amount): Money => 1 === $direction ? $amount : $amount->negated(), $bands);
            $row = new AgedBalanceRow($thirdParty, Money::sum($bands), $bands);
            if ($row->total->isZero()) {
                continue;
            }
            $rows[] = $row;
            $total = $total->plus($row);
        }

        return new AgedBalanceReport($kind, $window->to, $rows, $total);
    }
}
