<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\JournalType;
use App\Service\Accounting\Chart\AccountNumber;
use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Shared\Money;

/**
 * From the grid to the Ledger and back (design-ux.md § 5.2) - a pure function over the dossier's
 * lookups:
 *
 * - the Compte cell holds an account number, or a third party's auxiliary code whose collective
 *   account follows (« 401SCIE » → 401000 and the third party); the Tiers cell names the third party
 *   of a collective account typed by number (419100 and its customer);
 * - the entry's label is its first line's; a line keeps its own;
 * - a line of a purchase or a sale on a lettrable account that names a third party and has no due
 *   date gets the third party's (`PaymentTerms::dueDate()` at the entry's date);
 * - in a bank or cash journal, the counterpart line is automatic (Sage's « contrepartie
 *   automatique »): one line on the journal's account balances the lines typed, never typed itself.
 *
 * Faults the Ledger cannot name (a code that is neither an account nor a third party) come back in
 * `notes`, laid over EntryRules' errors under the same keys.
 */
final class GridDrafts
{
    /**
     * @param callable(string): ?Account    $accountOf
     * @param callable(string): ?ThirdParty $thirdPartyOf by auxiliary code, then by code
     *
     * @return array{draft: EntryDraft, notes: array<string, string>, counterpartIndex: ?int}
     */
    public static function draft(GridGroup $group, string $journalCode, JournalType $journalType, ?string $counterpart, callable $accountOf, callable $thirdPartyOf): array
    {
        $date = $group->day();
        $lines = [];
        $notes = [];
        $label = '';
        $invoicing = JournalType::Purchases === $journalType || JournalType::Sales === $journalType;
        foreach ($group->typedLines() as $index => $line) {
            if ('' === $label) {
                $label = trim($line->label);
            }
            $typed = strtoupper(AccountNumber::normalize($line->account));
            $number = $typed;
            $thirdParty = null;
            if ('' !== $typed && 1 !== preg_match('/^\d+$/', $typed)) {
                $thirdParty = $thirdPartyOf($typed);
                if (null === $thirdParty || null === $thirdParty->getAccount()) {
                    $notes['line.'.$index.'.account'] = null === $thirdParty
                        ? \sprintf('Ni compte ni tiers ne répond à %s. F4 pour chercher.', $typed)
                        : \sprintf('Le tiers %s n’est tenu sur aucun compte : il ne se saisit pas sur une écriture.', $typed);
                } else {
                    $number = $thirdParty->getAccount()->getNumber();
                }
            } elseif ('' !== trim($line->thirdParty)) {
                $thirdParty = $thirdPartyOf(strtoupper(trim($line->thirdParty)));
                if (null === $thirdParty) {
                    $notes['line.'.$index.'.thirdParty'] = \sprintf('Le tiers %s n’existe pas dans le dossier.', strtoupper(trim($line->thirdParty)));
                }
            }

            $due = null === $line->due ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $line->due) ?: null);
            if (null === $due && $invoicing && null !== $thirdParty && null !== $date) {
                $account = $accountOf($number);
                if (null !== $account && $account->isLettrable()) {
                    $due = $thirdParty->getPaymentTerms()->dueDate($date);
                }
            }

            $lines[] = new EntryLineDraft(
                $number,
                $thirdParty?->getCode() ?? ('' === trim($line->thirdParty) ? '' : strtoupper(trim($line->thirdParty))),
                trim($line->label),
                Money::cents($line->debit),
                Money::cents($line->credit),
                $due,
            );
        }

        $counterpartIndex = null;
        if (null !== $counterpart && [] !== $lines) {
            $gap = Money::sum(array_map(static fn (EntryLineDraft $l): Money => $l->debit, $lines))
                ->minus(Money::sum(array_map(static fn (EntryLineDraft $l): Money => $l->credit, $lines)));
            if (!$gap->isZero()) {
                $counterpartIndex = \count($lines);
                $lines[] = $gap->isPositive()
                    ? EntryLineDraft::credit($counterpart, $gap, $label)
                    : EntryLineDraft::debit($counterpart, $gap->abs(), $label);
            }
        }

        return [
            'draft' => new EntryDraft($journalCode, $date, $group->document, $label, $lines),
            'notes' => $notes,
            'counterpartIndex' => $counterpartIndex,
        ];
    }

    /**
     * An entry as the grid shows it: the auxiliary code in the Compte cell for a line on the third
     * party's own collective account, else the number and the third party in the Tiers cell. In a
     * bank or cash journal, the last line on its account is the automatic counterpart: left out.
     *
     * @return list<GridLine>
     */
    public static function linesOf(Entry $entry, ?string $counterpart): array
    {
        $lines = $entry->getLines();
        if (null !== $counterpart) {
            for ($i = \count($lines) - 1; $i >= 0; --$i) {
                if ($lines[$i]->getAccount()->getNumber() === $counterpart) {
                    array_splice($lines, $i, 1);
                    break;
                }
            }
        }

        return array_map(static function (EntryLine $line): GridLine {
            $thirdParty = $line->getThirdParty();
            $own = null !== $thirdParty && null !== $thirdParty->getAuxiliaryCode() && $thirdParty->getAccount() === $line->getAccount();

            return new GridLine(
                $own ? (string) $thirdParty->getAuxiliaryCode() : $line->getAccount()->getNumber(),
                $own || null === $thirdParty ? '' : ($thirdParty->getAuxiliaryCode() ?? $thirdParty->getCode()),
                $line->getLabel(),
                $line->getDebit()->cents,
                $line->getCredit()->cents,
                $line->getDueDate()?->format('Y-m-d'),
            );
        }, $lines);
    }
}
