<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Entry;

/**
 * An entry as typed in a journal, or as a module proposes it, before EntryRules checks it: its
 * texts come back normalized and every fault is named under its key - `journal`, `date`,
 * `document`, `label`, `lines` (the entry as a whole: too few lines, not balanced) and
 * `line.<i>.<field>` for the line at index i (account, thirdParty, label, amount, dueDate).
 */
final class EntryDraft
{
    /** @param list<EntryLineDraft> $lines */
    public function __construct(
        /** The journal's code (« ACH »). */
        public string $journal = '',
        public ?\DateTimeImmutable $date = null,
        /** The document's reference (« FA-2601-0012 »). */
        public string $document = '',
        public string $label = '',
        public array $lines = [],
    ) {
    }

    /** @var array<string, string> by key */
    public array $errors = [];

    public static function of(Entry $entry): self
    {
        return new self(
            $entry->getJournal()->getCode(),
            $entry->getEntryDate(),
            $entry->getDocument(),
            $entry->getLabel(),
            array_map(EntryLineDraft::of(...), $entry->getLines()),
        );
    }

    /** The first fault, for a sentence that names one. */
    public function firstError(): ?string
    {
        return [] === $this->errors ? null : (string) reset($this->errors);
    }
}
