<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\EntryLine;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Audit\AuditAction;
use App\Enum\Dossier\AccessLevel;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Dossier\Rights\DossierRights;
use App\Service\Shared\FrenchFormat;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the accounting entries (README of the plan, § 8; conception § 5): no other class
 * creates, changes, validates or deletes an Entry or an EntryLine (LedgerDoorTest), and modules go
 * through AccountingGateway, never here.
 *
 * - `draft()` saves an entry to the brouillard; `update()` and `discard()` change or delete one
 *   still there. Each checks the entry whole with EntryRules: an unbalanced entry is never written.
 * - `validate()` numbers entries without a gap, per journal and per exercise, in date order then as
 *   drafted, under a lock on each journal: two people validating at once never take one number.
 *   A validated entry never changes again (App\Doctrine\LedgerGuard holds it at flush).
 * - `reverse()` undoes a validated entry by its inverse, validated at once, dated as asked.
 *
 * The actor's profile in a group's dossier is asked here too (DossierRights, step 1.7): drafting,
 * changing and deleting need « saisie » on the journal, validating and reversing « validation ».
 *
 * Every gesture is checked again here (a period closed since the draft refuses it), recorded in
 * the audit trail and flushed in one transaction by the Ledger itself; a refusal is an
 * EntryRefused, which writes nothing. Runs inside the dossier's context.
 */
final readonly class Ledger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private JournalRepository $journals,
        private PeriodRepository $periods,
        private AccountRepository $accounts,
        private ThirdPartyRepository $thirdParties,
        private EntryRepository $entries,
        private AuditTrail $auditTrail,
        private DossierRights $rights,
    ) {
    }

    /** The draft checked against the dossier: its fields when it may be written, else null with its errors filled. */
    public function check(Dossier $dossier, EntryDraft $draft): ?EntryFields
    {
        return EntryRules::check(
            $draft,
            fn (string $code): ?Journal => $this->journals->findOneByCode($dossier, $code),
            fn (\DateTimeImmutable $day): ?Period => $this->periods->findAt($dossier, $day),
            fn (string $number) => $this->accounts->findOneByNumber($dossier, $number),
            fn (string $code) => $this->thirdParties->findOneByCode($dossier, $code),
        );
    }

    /** A new entry in the brouillard. */
    public function draft(Dossier $dossier, EntryDraft $draft, User|string $actor, EntryOrigin $origin = EntryOrigin::Manual, ?string $source = null): Entry
    {
        $fields = $this->checked($dossier, $draft);
        $this->refuseUnlessAllowed($fields->journal, $actor, AccessLevel::Enter);

        return $this->transactional(function () use ($dossier, $draft, $fields, $actor, $origin, $source): Entry {
            $entry = new Entry($dossier, $fields->journal, $fields->period, $draft->date ?? throw new \LogicException(), $draft->document, $draft->label, $origin, $source);
            $entry->ledgerReplaceLines(self::lines($entry, $fields));
            $this->entityManager->persist($entry);
            $this->entityManager->flush();
            $this->record(AuditAction::EntryDrafted, $actor, $entry, \sprintf('Écriture enregistrée au brouillard : %s', self::describe($entry)));

            return $entry;
        });
    }

    /** An entry of the brouillard rewritten whole: journal, date, document, label and lines. */
    public function update(Entry $entry, EntryDraft $draft, User|string $actor): void
    {
        self::refuseUnlessDraft($entry, 'modifier');
        $this->refuseUnlessAllowed($entry->getJournal(), $actor, AccessLevel::Enter);
        $fields = $this->checked($entry->getDossier(), $draft);
        $this->refuseUnlessAllowed($fields->journal, $actor, AccessLevel::Enter);

        $this->transactional(function () use ($entry, $draft, $fields, $actor): void {
            $entry->ledgerRewrite($fields->journal, $fields->period, $draft->date ?? throw new \LogicException(), $draft->document, $draft->label);
            $entry->ledgerReplaceLines(self::lines($entry, $fields));
            $this->record(AuditAction::EntryChanged, $actor, $entry, \sprintf('Écriture du brouillard modifiée : %s', self::describe($entry)));
        });
    }

    /** An entry of the brouillard deleted (a validated entry is reversed, never deleted). */
    public function discard(Entry $entry, User|string $actor): void
    {
        self::refuseUnlessDraft($entry, 'supprimer');
        $this->refuseUnlessAllowed($entry->getJournal(), $actor, AccessLevel::Enter);

        $this->transactional(function () use ($entry, $actor): void {
            $this->record(AuditAction::EntryDiscarded, $actor, $entry, \sprintf('Écriture du brouillard supprimée : %s', self::describe($entry)));
            $this->entityManager->remove($entry);
        });
    }

    /**
     * Entries of the brouillard validated: each checked again, then numbered in date order (then as
     * drafted) after the last number of its journal in its exercise. All or nothing: one refusal
     * validates none.
     *
     * @param list<Entry> $entries
     */
    public function validate(array $entries, User|string $actor): void
    {
        $errors = [];
        foreach ($entries as $entry) {
            if ($entry->isValidated()) {
                $errors['entry.'.$entry->getId()] = \sprintf('L’écriture %s est déjà validée.', $entry->getNumberLabel());
                continue;
            }
            $refusal = $this->rights->journalRefusal($entry->getJournal(), $actor, AccessLevel::Validate);
            if (null !== $refusal) {
                $errors['entry.'.$entry->getId()] = $refusal;
                continue;
            }
            $draft = EntryDraft::of($entry);
            if (null === $this->check($entry->getDossier(), $draft)) {
                $errors['entry.'.$entry->getId()] = \sprintf('%s : %s', self::describe($entry), $draft->firstError());
            }
        }
        if ([] !== $errors) {
            throw new EntryRefused($errors);
        }
        if ([] === $entries) {
            return;
        }

        usort($entries, static fn (Entry $a, Entry $b): int => [$a->getEntryDate(), $a->getId()] <=> [$b->getEntryDate(), $b->getId()]);

        $this->transactional(function () use ($entries, $actor): void {
            $this->lockJournals($entries);
            // Read again under the journals' lock: another request may have validated some of
            // these drafts since they were loaded (two people validating one brouillard), and a
            // stale draft would be numbered - and its number rewritten - a second time.
            $validated = [];
            foreach ($entries as $entry) {
                $this->entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
                if ($entry->isValidated()) {
                    $validated['entry.'.$entry->getId()] = \sprintf('L’écriture %s est déjà validée.', $entry->getNumberLabel());
                }
            }
            if ([] !== $validated) {
                throw new EntryRefused($validated);
            }
            $last = [];
            foreach ($entries as $entry) {
                $key = $entry->getJournal()->getId().'-'.$entry->getExercise()->getId();
                $last[$key] = ($last[$key] ?? $this->entries->lastNumber($entry->getJournal(), $entry->getExercise())) + 1;
                $entry->ledgerValidate($last[$key], $entry->getDossier()->getDossierDate());
                $this->record(AuditAction::EntryValidated, $actor, $entry, \sprintf('Écriture %s validée : %s', $entry->getNumberLabel(), self::describe($entry)));
            }
        });
    }

    /**
     * The brouillard validated, narrowed to a journal and/or a period (design-ux.md § 3.2: « validation
     * en lot »). Answers how many entries were validated.
     */
    public function validateDrafts(?Journal $journal, ?Period $period, User|string $actor): int
    {
        $drafts = $this->entries->findDrafts($journal, $period);
        $this->validate($drafts, $actor);

        return \count($drafts);
    }

    /**
     * A validated entry undone by its inverse (design-ux.md § 6.3): same journal, document and lines
     * with debit and credit swapped, dated `$on` (the dossier's date by default, on the screen),
     * validated at once and linked to the entry it reverses. An entry is reversed once; a reversal
     * is not reversed (its entry is entered again instead).
     */
    public function reverse(Entry $entry, \DateTimeImmutable $on, User|string $actor): Entry
    {
        if (!$entry->isValidated()) {
            throw EntryRefused::because('Une écriture du brouillard se modifie ou se supprime : seule une écriture validée se contrepasse.');
        }
        if (EntryOrigin::Reversal === $entry->getOrigin()) {
            throw EntryRefused::because(\sprintf('L’écriture %s est une contrepassation : saisissez de nouveau l’écriture d’origine plutôt que de la contrepasser.', $entry->getNumberLabel()));
        }
        $reversal = $this->entries->findReversalOf($entry);
        if (null !== $reversal) {
            throw EntryRefused::because(\sprintf('L’écriture %s est déjà contrepassée par %s.', $entry->getNumberLabel(), $reversal->getNumberLabel()));
        }
        $this->refuseUnlessAllowed($entry->getJournal(), $actor, AccessLevel::Validate);

        $label = mb_strimwidth(\sprintf('Contrepassation de %s : %s', $entry->getNumberLabel(), $entry->getLabel()), 0, EntryRules::LABEL_MAX, '…');
        $draft = new EntryDraft($entry->getJournal()->getCode(), $on, $entry->getDocument(), $label, array_map(
            static fn (EntryLine $line): EntryLineDraft => new EntryLineDraft($line->getAccount()->getNumber(), $line->getThirdParty()?->getCode() ?? '', $line->getLabel(), $line->getCredit(), $line->getDebit()),
            $entry->getLines(),
        ));
        $fields = $this->checked($entry->getDossier(), $draft);

        return $this->transactional(function () use ($entry, $on, $draft, $fields, $actor): Entry {
            $reversal = new Entry($entry->getDossier(), $fields->journal, $fields->period, $on, $draft->document, $draft->label, EntryOrigin::Reversal, $entry->getSource(), $entry);
            $reversal->ledgerReplaceLines(self::lines($reversal, $fields));
            $this->lockJournals([$reversal]);
            $reversal->ledgerValidate($this->entries->lastNumber($reversal->getJournal(), $reversal->getExercise()) + 1, $entry->getDossier()->getDossierDate());
            $this->entityManager->persist($reversal);
            $this->entityManager->flush();
            $this->record(AuditAction::EntryReversed, $actor, $entry, \sprintf('Écriture %s contrepassée par %s', $entry->getNumberLabel(), $reversal->getNumberLabel()), ['reversal' => $reversal->getId()]);

            return $reversal;
        });
    }

    private function checked(Dossier $dossier, EntryDraft $draft): EntryFields
    {
        return $this->check($dossier, $draft) ?? throw new EntryRefused($draft->errors);
    }

    /** @return list<EntryLine> */
    private static function lines(Entry $entry, EntryFields $fields): array
    {
        $lines = [];
        foreach ($fields->lines as $index => $line) {
            $lines[] = new EntryLine($entry, $index + 1, $line->account, $line->thirdParty, $line->label, $line->debit, $line->credit, $line->dueDate);
        }

        return $lines;
    }

    /** The actor's profile allows the gesture in the journal, or the Ledger refuses it under « journal ». */
    private function refuseUnlessAllowed(Journal $journal, User|string $actor, AccessLevel $wanted): void
    {
        $refusal = $this->rights->journalRefusal($journal, $actor, $wanted);
        if (null !== $refusal) {
            throw new EntryRefused(['journal' => $refusal]);
        }
    }

    private static function refuseUnlessDraft(Entry $entry, string $verb): void
    {
        if ($entry->isValidated()) {
            throw EntryRefused::because(\sprintf('L’écriture %s est validée : on ne peut plus la %s, seulement la contrepasser.', $entry->getNumberLabel(), $verb));
        }
    }

    /**
     * Each journal of the entries locked for the rest of the transaction, in id order (no deadlock
     * between two validations): the next number is read and written under it.
     *
     * @param list<Entry> $entries
     */
    private function lockJournals(array $entries): void
    {
        $journals = [];
        foreach ($entries as $entry) {
            $journals[(int) $entry->getJournal()->getId()] = $entry->getJournal();
        }
        ksort($journals);
        foreach ($journals as $journal) {
            $this->entityManager->lock($journal, LockMode::PESSIMISTIC_WRITE);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $gesture
     *
     * @return T
     */
    private function transactional(callable $gesture): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $gesture();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();
            throw $failure;
        }

        return $result;
    }

    /** @param array<string, mixed> $details */
    private function record(AuditAction $action, User|string $actor, Entry $entry, string $summary, array $details = []): void
    {
        $this->auditTrail->inDossier($entry->getDossier(), $action, $actor, $summary, [
            'dossier' => $entry->getDossier()->getId(),
            'entry' => $entry->getId(),
            'journal' => $entry->getJournal()->getCode(),
            'date' => $entry->getEntryDate()->format('Y-m-d'),
            'document' => $entry->getDocument(),
            'number' => $entry->getNumberLabel(),
            'amount' => $entry->totalDebit()->cents,
            ...$details,
        ]);
    }

    /** « ACH · 08/01/2026 · FA-2601-0012 · 4 158,12 » */
    private static function describe(Entry $entry): string
    {
        return \sprintf('%s · %s · %s · %s', $entry->getJournal()->getCode(), FrenchFormat::date($entry->getEntryDate()), $entry->getDocument(), FrenchFormat::amount($entry->totalDebit()));
    }
}
