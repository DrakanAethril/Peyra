<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Period;
use App\Entity\Identity\User;
use App\Repository\Accounting\EntryRepository;
use App\Service\Accounting\Entry\GridContext;
use App\Service\Accounting\Entry\GridDrafts;
use App\Service\Accounting\Entry\GridGroup;
use App\Service\Accounting\Entry\JournalGrid;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Accounting\Ledger\Ledger;

/**
 * Comptabilité › Brouillard (lot 1, step 1.5; design-ux.md § 3.2, « Brouillard »): the entries not
 * yet validated, each checked as the grid checks it (JournalGrid::check(): the Ledger's rules and
 * the controls of the dossier's level of assistance), and the validation of a selection - all or
 * nothing, through the Ledger. At levels G and B a draft a control stops is held back (its box
 * cannot be ticked); at level A its controls are warnings. A draft the person is changing in the
 * grid is held back too, as the grid's Ctrl Entrée does. Runs in the dossier's context.
 */
final readonly class Brouillard
{
    public function __construct(
        private EntryRepository $entries,
        private JournalGrid $grid,
        private PendingEntries $pending,
        private Ledger $ledger,
    ) {
    }

    /** @return list<BrouillardRow> */
    public function rows(Dossier $dossier, ?Journal $journal, ?Period $period, ?User $me): array
    {
        return $this->check($dossier, $this->entries->findDrafts($journal, $period), $me);
    }

    /**
     * The drafts named validated, but those held back. Ids that are not drafts of the dossier are
     * ignored (validated or deleted since the page was drawn).
     *
     * @param list<int> $ids
     */
    public function validate(Dossier $dossier, array $ids, User $me): BrouillardOutcome
    {
        $wanted = array_flip($ids);
        $drafts = array_values(array_filter($this->entries->findDrafts(), static fn (Entry $entry): bool => isset($wanted[(int) $entry->getId()])));
        $ready = [];
        $held = 0;
        foreach ($this->check($dossier, $drafts, $me) as $row) {
            if ($row->isSelectable()) {
                $ready[] = $row->entry;
            } else {
                ++$held;
            }
        }
        try {
            $this->ledger->validate($ready, $me);
        } catch (EntryRefused $refusal) {
            return new BrouillardOutcome([], $held, $refusal->getMessage());
        }

        return new BrouillardOutcome($ready, $held, null);
    }

    /**
     * @param list<Entry> $drafts
     *
     * @return list<BrouillardRow>
     */
    private function check(Dossier $dossier, array $drafts, ?User $me): array
    {
        /** @var array<string, GridContext> $contexts */
        $contexts = [];
        /** @var array<string, array<int, true>> $changing by context, the drafts the person's pending work changes */
        $changing = [];
        $rows = [];
        foreach ($drafts as $draft) {
            $key = $draft->getJournal()->getId().'-'.$draft->getPeriod()->getId();
            if (!isset($contexts[$key])) {
                $contexts[$key] = $this->grid->context($dossier, $draft->getJournal(), $draft->getPeriod());
                $changing[$key] = [];
                if (null !== $me) {
                    foreach ($this->pending->groups($draft->getJournal(), $draft->getPeriod(), $me) as $group) {
                        if (null !== $group->entry) {
                            $changing[$key][$group->entry] = true;
                        }
                    }
                }
            }
            $context = $contexts[$key];
            $check = $this->grid->check($context, new GridGroup('e'.$draft->getId(), $draft->getId(), $draft->getEntryDate()->format('Y-m-d'), $draft->getDocument(), GridDrafts::linesOf($draft, $context->counterpart())));
            $rows[] = new BrouillardRow($draft, $check->controls, $check->errors, !$check->isWritable(), isset($changing[$key][(int) $draft->getId()]));
        }

        return $rows;
    }
}
