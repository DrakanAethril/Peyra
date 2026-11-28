<?php

declare(strict_types=1);

namespace App\Service\ThirdParty\Schedule;

use App\Entity\Accounting\EntryLine;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AgeBand;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Edition\AgedBalance;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\LedgerReader;

/**
 * The échéancier of the customers or of the suppliers (step 2.7; design-ux.md § 3.2, Ventes › Suivi
 * and Achats › Règlements): at a day - the dossier's date -, the lines each third party's balance is
 * made of, read as the aged balance reads them (AgedBalance::openParts(): the newest lines on its
 * side, the last taken in part), so that the two always agree and no lettering is needed. Every
 * line naming the third party since its exercise opened, brouillard included. Inside the dossier's
 * context.
 */
final readonly class DueSchedule
{
    public function __construct(
        private LedgerReader $reader,
        private ThirdPartyRepository $thirdParties,
    ) {
    }

    public function read(Dossier $dossier, ThirdPartyKind $kind, \DateTimeImmutable $at): DueScheduleReport
    {
        $lines = $this->reader->linesByThirdParty($dossier, $this->reader->exerciseStart($dossier, $at), $at, EntryScope::WithDrafts);
        $sign = ThirdPartyKind::Customer === $kind ? 1 : -1;

        $items = [];
        foreach ($this->thirdParties->findByDossier($dossier) as $thirdParty) {
            $own = $lines[(int) $thirdParty->getId()] ?? [];
            if ($kind !== $thirdParty->getKind() || [] === $own) {
                continue;
            }
            $read = array_map(static fn (EntryLine $line): array => [
                'amount' => $line->getDebit()->minus($line->getCredit()),
                'day' => $line->getEntry()->getEntryDate(),
                'due' => $line->getDueDate(),
            ], $own);
            foreach (AgedBalance::openParts($read) as $index => $part) {
                $due = $read[$index]['due'] ?? $read[$index]['day'];
                $late = (int) (new \DateTimeImmutable($due->format('Y-m-d')))->diff(new \DateTimeImmutable($at->format('Y-m-d')))->format('%r%a');
                $items[] = new DueItem(
                    $thirdParty,
                    $own[$index],
                    $due,
                    1 === $sign ? $read[$index]['amount'] : $read[$index]['amount']->negated(),
                    1 === $sign ? $part : $part->negated(),
                    AgeBand::of($due, $at),
                    max(0, $late),
                );
            }
        }
        usort($items, static fn (DueItem $a, DueItem $b): int => [$a->dueOn->format('Y-m-d'), $a->thirdParty->getCode(), $a->line->getId()] <=> [$b->dueOn->format('Y-m-d'), $b->thirdParty->getCode(), $b->line->getId()]);

        return new DueScheduleReport($kind, $at, $items);
    }
}
