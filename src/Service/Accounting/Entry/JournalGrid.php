<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\EntryRules;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Accounting\Ledger\Ledger;
use App\Service\Accounting\Ledger\LedgerReader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The entry grid of a journal and a month (lot 1, step 1.4; design-ux.md §§ 4.4 and 5.2), on the
 * server's side: what the browser is given, what it keeps on each line left, and what F10 and
 * Ctrl Entrée do with it.
 *
 * - `state()`: the month's entries of the journal - validated ones to read, drafts to change -, then
 *   the person's pending work (PendingEntry): the drafts they are changing, the entries not yet
 *   saved; each with its checks.
 * - `keep()`: the pending work saved as typed, whatever its state (a line left, design-ux.md
 *   § 5.2), and checked.
 * - `save()` (F10): every pending entry that the Ledger accepts and no blocking control stops goes
 *   to the brouillard - a new one by `Ledger::draft()`, a changed draft by `update()`, a draft whose
 *   lines were all deleted by `discard()` -; the others stay pending, as typed.
 * - `validate()` (Ctrl Entrée): `save()`, then the month's brouillard of the journal validated, but
 *   for the drafts still being changed and, at levels G and B, those a control stops.
 *
 * Runs in the dossier's context; the Ledger flushes its own gestures, this flushes the pending work.
 */
final readonly class JournalGrid
{
    public function __construct(
        private EntryRepository $entries,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private PeriodRepository $periods,
        private PendingEntries $pending,
        private Ledger $ledger,
        private AssistanceLevels $assistance,
        private EntityManagerInterface $entityManager,
        private LedgerReader $reader,
    ) {
    }

    /**
     * What the grid looks accounts and third parties up in while a person types, without asking the
     * server again (Sage's speed, design-ux.md § 5.2): the plan - number, label, collective,
     * lettrable, balance - and the third parties kept on an account - auxiliary code, code, name,
     * account, balance. Balances in cents (debit minus credit) at the dossier's date since its
     * exercise opened, brouillard included, as the F4 list shows them; the grid adds what is typed.
     *
     * @return array{accounts: list<array{n: string, l: string, c: bool, t: bool, b: int}>, thirdParties: list<array{a: ?string, c: string, n: string, k: string, b: int}>}
     */
    public function lookup(GridContext $context): array
    {
        $dossier = $context->dossier;
        $today = $dossier->getDossierDate();
        $from = $this->reader->exerciseStart($dossier, $today);
        $accountTotals = $this->reader->accountTotals($dossier, $from, $today, EntryScope::WithDrafts);
        $thirdPartyTotals = $this->reader->thirdPartyTotals($dossier, $from, $today, EntryScope::WithDrafts);

        $accounts = [];
        foreach ($this->accounts->findPlan($dossier) as $account) {
            $accounts[] = [
                'n' => $account->getNumber(),
                'l' => $account->getLabel(),
                'c' => $account->isCollective(),
                't' => $account->isLettrable(),
                'b' => ($accountTotals[(int) $account->getId()] ?? null)?->balance()->cents ?? 0,
            ];
        }
        $thirdParties = [];
        foreach ($this->thirdParties->findByDossier($dossier) as $thirdParty) {
            if (null === $thirdParty->getAccount()) {
                continue;
            }
            $thirdParties[] = [
                'a' => $thirdParty->getAuxiliaryCode(),
                'c' => $thirdParty->getCode(),
                'n' => $thirdParty->getName(),
                'k' => $thirdParty->getAccount()->getNumber(),
                'b' => ($thirdPartyTotals[(int) $thirdParty->getId()] ?? null)?->balance()->cents ?? 0,
            ];
        }

        return ['accounts' => $accounts, 'thirdParties' => $thirdParties];
    }

    /** The journal's account (bank or cash) at the month's end, brouillard included: the grid's « solde progressif ». */
    public function counterpartBalance(GridContext $context): ?int
    {
        $account = $context->journal->getCounterpartAccount();
        if (null === $account) {
            return null;
        }
        $end = $context->period->getEndsOn();

        return $this->reader->accountBalance($account, $this->reader->exerciseStart($context->dossier, $end), $end, EntryScope::WithDrafts)->balance()->cents;
    }

    public function context(Dossier $dossier, Journal $journal, Period $period): GridContext
    {
        $documents = [];
        foreach ($this->entries->findBy(['journal' => $journal, 'exercise' => $period->getExercise()]) as $entry) {
            $documents[] = ['id' => (int) $entry->getId(), 'document' => $entry->getDocument(), 'label' => $entry->getNumberLabel() ?? 'brouillard du '.$entry->getEntryDate()->format('d/m/Y')];
        }

        return new GridContext(
            $dossier,
            $journal,
            $period,
            $this->assistance->for($dossier),
            $this->accounts->findPlan($dossier),
            $this->thirdParties->findByDossier($dossier),
            $this->periods->findByDossier($dossier),
            $documents,
        );
    }

    /**
     * One group checked as F10 would: EntryRules over the dossier, the grid's own faults, the
     * controls of the assistance level, and the due dates it fills.
     *
     * @param list<GridGroup> $siblings the other groups of the grid (a document typed twice)
     */
    public function check(GridContext $context, GridGroup $group, array $siblings = []): GridCheck
    {
        $journal = $context->journal;
        $made = GridDrafts::draft($group, $journal->getCode(), $journal->getType(), $context->counterpart(), $context->account(...), $context->thirdParty(...));
        $draft = $made['draft'];

        $fields = EntryRules::check(
            $draft,
            static fn (string $code): ?Journal => $code === $journal->getCode() ? $journal : null,
            $context->periodAt(...),
            $context->account(...),
            $context->thirdParty(...),
        );
        $errors = [...$draft->errors, ...$made['notes']];
        if (null !== $made['counterpartIndex']) {
            // The automatic line is nobody's to correct: its faults are the entry's.
            foreach ($errors as $key => $message) {
                if (str_starts_with($key, 'line.'.$made['counterpartIndex'].'.')) {
                    unset($errors[$key]);
                    $errors['lines'] ??= $message;
                }
            }
        }
        if (null !== $group->entry && !$this->isOpenDraft($group->entry)) {
            $errors['entry'] = 'Cette écriture n’est plus au brouillard : elle a été validée ou supprimée entre-temps. Abandonnez ces modifications (Échap).';
        }

        $controls = EntryControls::check(
            $draft,
            $journal->getType(),
            $context->counterpart(),
            $context->period->getStartsOn(),
            $context->period->getEndsOn(),
            $context->dossier->getDossierDate(),
            $context->level,
            $context->account(...),
            static function (string $document) use ($context, $group, $siblings): ?string {
                foreach ($context->documents as $known) {
                    if ($known['document'] === $document && $known['id'] !== $group->entry) {
                        return $known['label'];
                    }
                }
                foreach ($siblings as $sibling) {
                    if ($sibling->key !== $group->key && trim($sibling->document) === $document && (null === $sibling->entry || $sibling->entry !== $group->entry)) {
                        return 'une autre écriture en cours de saisie';
                    }
                }

                return null;
            },
        );

        $dues = [];
        foreach ($group->typedLines() as $index => $line) {
            $due = $draft->lines[$index]->dueDate ?? null;
            if (null === $line->due && null !== $due) {
                $dues[$index] = $due->format('Y-m-d');
            }
        }

        return new GridCheck($draft, $fields, $errors, $controls, $dues);
    }

    /**
     * What the browser is given to draw the grid: the month's entries of the journal, the person's
     * pending work laid over the drafts it changes, then their new entries.
     *
     * @return list<array<string, mixed>>
     */
    public function groups(GridContext $context, ?User $me): array
    {
        $counterpart = $context->counterpart();
        $pendingGroups = null === $me ? [] : $this->pending->groups($context->journal, $context->period, $me);
        $changing = [];
        foreach ($pendingGroups as $group) {
            if (null !== $group->entry) {
                $changing[$group->entry] = $group;
            }
        }

        $rows = [];
        $listed = [];
        foreach ($this->entries->findInJournalPeriod($context->journal, $context->period) as $entry) {
            $id = (int) $entry->getId();
            $listed[$id] = true;
            $stored = GridDrafts::linesOf($entry, $counterpart);
            $row = [
                'key' => 'e'.$id,
                'entry' => $id,
                'state' => $entry->isValidated() ? 'validated' : 'draft',
                'number' => $entry->getNumberLabel(),
                'reversed' => null,
                'modified' => false,
                'date' => $entry->getEntryDate()->format('Y-m-d'),
                'document' => $entry->getDocument(),
                'lines' => array_map(static fn (GridLine $line): array => $line->toArray(), $stored),
                'original' => array_map(static fn (GridLine $line): array => $line->toArray(), $stored),
                'errors' => [],
                'controls' => [],
                'dues' => [],
            ];
            if ($entry->isValidated()) {
                $reversal = $this->entries->findReversalOf($entry);
                $row['reversed'] = null !== $reversal ? 'Contrepassée par '.$reversal->getNumberLabel() : (null !== $entry->getReversalOf() ? 'Contrepassation de '.$entry->getReversalOf()->getNumberLabel() : null);
            } elseif (isset($changing[$id])) {
                $group = $changing[$id];
                $row['key'] = $group->key;
                $row['modified'] = true;
                $row['date'] = $group->date;
                $row['document'] = $group->document;
                $row['lines'] = array_map(static fn (GridLine $line): array => $line->toArray(), $group->typedLines());
                $row = [...$row, ...$this->check($context, $group, $pendingGroups)->toArray()];
            } else {
                $group = new GridGroup('e'.$id, $id, $row['date'], $entry->getDocument(), $stored);
                $row = [...$row, ...$this->check($context, $group)->toArray()];
            }
            $rows[] = $row;
        }

        foreach ($pendingGroups as $group) {
            if (null !== $group->entry && isset($listed[$group->entry])) {
                continue;
            }
            $rows[] = [
                'key' => $group->key,
                'entry' => $group->entry,
                'state' => null === $group->entry ? 'new' : 'draft',
                'number' => null,
                'reversed' => null,
                'modified' => true,
                'date' => $group->date,
                'document' => $group->document,
                'lines' => array_map(static fn (GridLine $line): array => $line->toArray(), $group->typedLines()),
                'original' => [],
                ...$this->check($context, $group, $pendingGroups)->toArray(),
            ];
        }

        return $rows;
    }

    /**
     * The person's pending work kept as typed (a line left), and each group's checks by its key.
     *
     * @param list<GridGroup> $groups
     *
     * @return array{revision: int, checks: array<string, array{errors: array<string, string>, controls: list<array{key: string, message: string, blocking: bool}>, dues: list<array{index: int, date: string}>}>}
     *
     * @throws PendingStale
     */
    public function keep(GridContext $context, User $me, array $groups, ?int $revision, \DateTimeImmutable $now): array
    {
        $row = $this->pending->keep($context->journal, $context->period, $me, $groups, $revision, $now);
        $this->entityManager->flush();

        $checks = [];
        foreach ($groups as $group) {
            $checks[$group->key] = $this->check($context, $group, $groups)->toArray();
        }

        return ['revision' => $row->getRevision(), 'checks' => $checks];
    }

    /**
     * F10: the pending work kept, then every entry of it the Ledger accepts written to the
     * brouillard; what is not stays pending, as typed.
     *
     * @param list<GridGroup> $groups
     *
     * @throws PendingStale
     */
    public function save(GridContext $context, User $me, array $groups, ?int $revision, \DateTimeImmutable $now): GridOutcome
    {
        $this->pending->keep($context->journal, $context->period, $me, $groups, $revision, $now);
        $this->entityManager->flush();
        $kept = $this->pending->groups($context->journal, $context->period, $me);

        $outcome = new GridOutcome();
        $left = [];
        foreach ($kept as $group) {
            if (null !== $group->entry && [] === $group->typedLines()) {
                $entry = $this->entries->find($group->entry);
                if (null !== $entry && !$entry->isValidated()) {
                    $this->ledger->discard($entry, $me);
                    ++$outcome->discarded;
                }
                continue;
            }
            $check = $this->check($context, $group, $kept);
            if (!$check->isWritable()) {
                $left[] = $group;
                $outcome->keepFault($check);
                continue;
            }
            try {
                if (null !== $group->entry) {
                    $entry = $this->entries->find($group->entry) ?? throw EntryRefused::because('L’écriture n’existe plus.');
                    $this->ledger->update($entry, $check->draft, $me);
                } else {
                    $this->ledger->draft($context->dossier, $check->draft, $me);
                }
                ++$outcome->saved;
            } catch (EntryRefused $refusal) {
                $left[] = $group;
                $outcome->fault ??= $refusal->getMessage();
            }
        }
        $outcome->left = \count($left);
        $this->pending->keep($context->journal, $context->period, $me, $left, null, $now);
        $this->entityManager->flush();

        return $outcome;
    }

    /**
     * Ctrl Entrée: F10, then the month's brouillard of the journal validated, numbered without a gap
     * (Ledger::validate(), all or nothing) - but for the drafts the person is still changing and,
     * when the level blocks, those a control stops.
     *
     * @param list<GridGroup> $groups
     *
     * @throws PendingStale
     */
    public function validate(GridContext $context, User $me, array $groups, ?int $revision, \DateTimeImmutable $now): GridOutcome
    {
        $outcome = $this->save($context, $me, $groups, $revision, $now);

        $changing = [];
        foreach ($this->pending->groups($context->journal, $context->period, $me) as $group) {
            if (null !== $group->entry) {
                $changing[$group->entry] = true;
            }
        }
        $ready = [];
        foreach ($this->entries->findDrafts($context->journal, $context->period) as $draft) {
            if (isset($changing[(int) $draft->getId()])) {
                ++$outcome->held;
                continue;
            }
            if ($context->level->blocks()) {
                $check = $this->check($context, new GridGroup('e'.$draft->getId(), $draft->getId(), $draft->getEntryDate()->format('Y-m-d'), $draft->getDocument(), GridDrafts::linesOf($draft, $context->counterpart())));
                if (!$check->isWritable()) {
                    ++$outcome->held;
                    continue;
                }
            }
            $ready[] = $draft;
        }

        try {
            $this->ledger->validate($ready, $me);
            $outcome->validated = $ready;
        } catch (EntryRefused $refusal) {
            $outcome->fault = $refusal->getMessage();
        }

        return $outcome;
    }

    /** A group added at the end of the person's pending work (a duplicate, a copy to enter again). */
    public function append(Journal $journal, Period $period, User $me, GridGroup $group, \DateTimeImmutable $now): void
    {
        $groups = $this->pending->groups($journal, $period, $me);
        $groups[] = $group;
        $this->pending->keep($journal, $period, $me, $groups, null, $now);
        $this->entityManager->flush();
    }

    private function isOpenDraft(int $id): bool
    {
        $entry = $this->entries->find($id);

        return $entry instanceof Entry && !$entry->isValidated();
    }
}
