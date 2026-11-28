<?php

declare(strict_types=1);

namespace App\Service\Accounting\Opening;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Enum\Accounting\JournalType;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Accounting\PendingEntryRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Shared\Money;

/**
 * Reads the opening balance of an exercise (lot 1, step 1.6; Comptabilité › Saisie ›
 * À-nouveaux): the entries of the dossier's à-nouveaux journal - dated the exercise's first day,
 * EntryRules sees to it -, summed by account, and the new à-nouveaux typed in the grid of its
 * first month that are not yet in the brouillard (PendingEntry): the one place an opening balance
 * can still be unbalanced. Runs in the dossier's context.
 */
final readonly class OpeningBalance
{
    public function __construct(
        private JournalRepository $journals,
        private EntryRepository $entries,
        private PeriodRepository $periods,
        private PendingEntryRepository $pending,
    ) {
    }

    public function of(Dossier $dossier, Exercise $exercise): OpeningReport
    {
        $journal = $this->journals->findByType($dossier, JournalType::Opening)[0] ?? null;
        $firstPeriod = $this->periods->findByExercise($exercise)[0] ?? null;
        if (null === $journal) {
            return new OpeningReport($exercise, null, $firstPeriod, [], [], [], []);
        }

        $entries = $this->entries->findInJournalExercise($journal, $exercise);
        /** @var array<int, array{account: Account, lines: int, debit: Money, credit: Money}> $byAccount */
        $byAccount = [];
        $resultAccounts = [];
        foreach ($entries as $entry) {
            foreach ($entry->getLines() as $line) {
                $account = $line->getAccount();
                $id = (int) $account->getId();
                $byAccount[$id] ??= ['account' => $account, 'lines' => 0, 'debit' => Money::zero(), 'credit' => Money::zero()];
                ++$byAccount[$id]['lines'];
                $byAccount[$id]['debit'] = $byAccount[$id]['debit']->plus($line->getDebit());
                $byAccount[$id]['credit'] = $byAccount[$id]['credit']->plus($line->getCredit());
                if (\in_array($account->getClassDigit(), [6, 7], true)) {
                    $resultAccounts[$id] = $account;
                }
            }
        }
        $rows = array_map(static fn (array $row): OpeningAccountRow => new OpeningAccountRow($row['account'], $row['lines'], $row['debit'], $row['credit']), array_values($byAccount));
        usort($rows, static fn (OpeningAccountRow $a, OpeningAccountRow $b): int => strcmp($a->account->getNumber(), $b->account->getNumber()));
        $resultAccounts = array_values($resultAccounts);
        usort($resultAccounts, static fn (Account $a, Account $b): int => strcmp($a->getNumber(), $b->getNumber()));

        $pending = [];
        if (null !== $firstPeriod) {
            foreach ($this->pending->findAllPending($journal, $firstPeriod) as $work) {
                $debit = 0;
                $credit = 0;
                $lines = 0;
                $groups = 0;
                foreach ($work->getGroups() as $group) {
                    // A draft being changed stays counted as the entry it is until F10 rewrites it.
                    if ($group->isBlank() || null !== $group->entry) {
                        continue;
                    }
                    ++$groups;
                    foreach ($group->typedLines() as $line) {
                        ++$lines;
                        $debit += $line->debit;
                        $credit += $line->credit;
                    }
                }
                if ($groups > 0) {
                    $pending[] = new OpeningPending($work->getAuthor(), $groups, $lines, Money::cents($debit), Money::cents($credit));
                }
            }
        }

        return new OpeningReport($exercise, $journal, $firstPeriod, $entries, $rows, $pending, $resultAccounts);
    }
}
