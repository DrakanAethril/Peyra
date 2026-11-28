<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/**
 * The bank reconciliation's rules (step 2.6a; design-ux.md § 4.5), pure functions over the two
 * sides' movements: what a pointing must hold, what the état de rapprochement says, what refuses
 * a validation, which pairs are proposed. Pointings, ReconciliationBoard and the tests read them.
 */
final class ReconciliationRules
{
    /** Days apart a statement line and an entry may be and still be proposed together (design-ux.md § 4.5: « à ± 3 jours »). */
    public const int PROPOSAL_DAYS = 3;

    /**
     * Why a selection cannot be pointed, or null: two movements at least, and the statement's side
     * adding up to the books' side to the cent - a cheque against its entry, a remittance against
     * the cheques it holds, an entry and its reversal.
     *
     * @param list<Money> $statementSide
     * @param list<Money> $bookSide
     */
    public static function pointingRefusal(array $statementSide, array $bookSide): ?string
    {
        if (\count($statementSide) + \count($bookSide) < 2) {
            return 'Choisissez une ligne du relevé et l’écriture qui lui répond : Espace sur chacune, Tab pour changer de côté.';
        }
        $bank = Money::sum($statementSide);
        $books = Money::sum($bookSide);
        if (!$bank->equals($books)) {
            return \sprintf('La sélection ne tombe pas juste : %s au relevé, %s en comptabilité.', FrenchFormat::amount($bank), FrenchFormat::amount($books));
        }

        return null;
    }

    /**
     * The état de rapprochement of a statement: `accountBalance` is the bank account's balance at the
     * statement's last day (debit minus credit, the brouillard included), `statement` its lines,
     * `books` the movements of the account the statement may point (this statement's pointings and
     * those no statement holds), `earlierToPost` what earlier statements left unpointed. A letter
     * whose two sides no longer add up is broken: its movements are read as unpointed.
     *
     * @param list<ReconciliationItem> $statement
     * @param list<ReconciliationItem> $books
     */
    public static function report(Money $accountBalance, Money $closingBalance, array $statement, array $books, Money $earlierToPost): ReconciliationReport
    {
        $sums = [];
        foreach ([[$statement, 0], [$books, 1]] as [$items, $side]) {
            foreach ($items as $item) {
                if (null !== $item->letter) {
                    $sums[$item->letter] ??= [Money::zero(), Money::zero()];
                    $sums[$item->letter][$side] = $sums[$item->letter][$side]->plus($item->amount);
                }
            }
        }
        $broken = [];
        foreach ($sums as $letter => [$bank, $bookSum]) {
            if (!$bank->equals($bookSum)) {
                $broken[] = (string) $letter;
            }
        }
        $open = static fn (ReconciliationItem $item): bool => null === $item->letter || \in_array($item->letter, $broken, true);
        $toPost = array_values(array_filter($statement, $open));

        return new ReconciliationReport(
            $accountBalance,
            $closingBalance,
            $toPost,
            $earlierToPost,
            array_values(array_filter($books, $open)),
            $broken,
            \count($statement) - \count($toPost),
            \count($statement),
        );
    }

    /**
     * What stands against validating the reconciliation (design-ux.md § 4.5, point 6), or null: an
     * état that does not fall right, then a statement line neither pointed nor posted. Assistance
     * decides whether it refuses (G, B), warns (A) or says nothing (0).
     */
    public static function validationRefusal(ReconciliationReport $report): ?string
    {
        if ([] !== $report->brokenLetters) {
            return \sprintf('Le pointage %s ne tombe plus juste : une écriture pointée a changé. Dépointez-le, puis pointez de nouveau.', implode(', ', $report->brokenLetters));
        }
        if (!$report->gap()->isZero()) {
            return \sprintf('L’état ne tombe pas juste : écart de %s.', FrenchFormat::amount($report->gap()->abs()));
        }
        $count = \count($report->toPost);
        if (0 < $count) {
            return \sprintf(
                '%s du relevé %s ni %s ni %s (%s).',
                1 === $count ? 'Une ligne' : $count.' lignes',
                1 === $count ? 'n’est' : 'ne sont',
                1 === $count ? 'pointée' : 'pointées',
                1 === $count ? 'comptabilisée' : 'comptabilisées',
                FrenchFormat::amount($report->toPostTotal()->abs()),
            );
        }

        return null;
    }

    /**
     * The pairs proposed at level G (design-ux.md § 4.5, point 2): each open statement line, in the
     * statement's order, with the open entry of the same amount whose date is nearest and at most
     * PROPOSAL_DAYS away, each entry proposed once.
     *
     * @param list<ReconciliationItem> $statement
     * @param list<ReconciliationItem> $books
     *
     * @return list<array{statement: int, entry: int}>
     */
    public static function proposals(array $statement, array $books): array
    {
        $taken = [];
        $pairs = [];
        foreach ($statement as $line) {
            if (null !== $line->letter) {
                continue;
            }
            $best = null;
            $bestDays = self::PROPOSAL_DAYS + 1;
            foreach ($books as $entry) {
                if (null !== $entry->letter || isset($taken[$entry->id]) || !$entry->amount->equals($line->amount)) {
                    continue;
                }
                $days = (int) $line->date->diff($entry->date)->days;
                if ($days < $bestDays) {
                    [$best, $bestDays] = [$entry, $days];
                }
            }
            if (null !== $best) {
                $taken[$best->id] = true;
                $pairs[] = ['statement' => $line->id, 'entry' => $best->id];
            }
        }

        return $pairs;
    }
}
