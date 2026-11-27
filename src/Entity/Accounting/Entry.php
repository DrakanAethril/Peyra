<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use App\Enum\Accounting\EntryOrigin;
use App\Enum\Accounting\EntryState;
use App\Repository\Accounting\EntryRepository;
use App\Service\Shared\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An accounting entry (« écriture », conception § 5): a journal, a date, a document reference
 * (« pièce »), a label and its balanced lines. In the brouillard it is changed and deleted; once
 * validated it carries its definitive number - continuous per journal and per exercise - and never
 * changes again: it is reversed (`reversalOf` names the entry a reversal undoes).
 *
 * App\Service\Accounting\Ledger\Ledger is the only class that creates or changes one (README of the
 * plan, § 8; LedgerDoorTest), and App\Doctrine\LedgerGuard refuses at flush an unbalanced entry and
 * any change to a validated one. Its methods that change it are named `ledger…` so that the test
 * finds a caller outside the Ledger.
 */
#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entry')]
#[ORM\UniqueConstraint(name: 'uniq_entry_number', columns: ['journal_id', 'exercise_id', 'number'])]
#[ORM\UniqueConstraint(name: 'uniq_entry_reversal_of', columns: ['reversal_of_id'])]
#[ORM\Index(name: 'idx_entry_dossier_date', columns: ['dossier_id', 'entry_date'])]
class Entry implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Journal $journal;

    /** The exercise of its date: the numbering's sequence is the journal's in this exercise. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Exercise $exercise;

    /** The month of its date; a closed period refuses it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Period $period;

    /** The day the entry is dated (a day of the dossier's calendar, never the server's). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $entryDate;

    /** The document's reference (« pièce »: FA-2601-0012, AN-2026-001), the FEC's PieceRef. */
    #[ORM\Column(length: 40)]
    private string $document;

    #[ORM\Column(length: 200)]
    private string $label;

    #[ORM\Column(length: 12, enumType: EntryState::class)]
    private EntryState $state = EntryState::Draft;

    /** Its rank in the journal and the exercise, given at validation without a gap. */
    #[ORM\Column(nullable: true)]
    private ?int $number = null;

    /** The dossier's date the day it was validated (the FEC's ValidDate). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedOn = null;

    #[ORM\Column(length: 12, enumType: EntryOrigin::class)]
    private EntryOrigin $origin;

    /** For a proposal, the module that asked for it (AccountingModule's value). */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $source;

    /** The validated entry this one reverses; an entry is reversed once at most. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Entry $reversalOf;

    /** @var Collection<int, EntryLine> */
    #[ORM\OneToMany(targetEntity: EntryLine::class, mappedBy: 'entry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(Dossier $dossier, Journal $journal, Period $period, \DateTimeImmutable $entryDate, string $document, string $label, EntryOrigin $origin, ?string $source = null, ?self $reversalOf = null)
    {
        $this->dossier = $dossier;
        $this->journal = $journal;
        $this->period = $period;
        $this->exercise = $period->getExercise();
        $this->entryDate = $entryDate->setTime(0, 0);
        $this->document = $document;
        $this->label = $label;
        $this->origin = $origin;
        $this->source = $source;
        $this->reversalOf = $reversalOf;
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJournal(): Journal
    {
        return $this->journal;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getPeriod(): Period
    {
        return $this->period;
    }

    public function getEntryDate(): \DateTimeImmutable
    {
        return $this->entryDate;
    }

    public function getDocument(): string
    {
        return $this->document;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getState(): EntryState
    {
        return $this->state;
    }

    public function isValidated(): bool
    {
        return EntryState::Validated === $this->state;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    /** « ACH-2026-00012 »: the journal, the exercise and the rank; null in the brouillard. */
    public function getNumberLabel(): ?string
    {
        return null === $this->number ? null : \sprintf('%s-%s-%05d', $this->journal->getCode(), $this->exercise->getCode(), $this->number);
    }

    public function getValidatedOn(): ?\DateTimeImmutable
    {
        return $this->validatedOn;
    }

    public function getOrigin(): EntryOrigin
    {
        return $this->origin;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getReversalOf(): ?self
    {
        return $this->reversalOf;
    }

    /** @return list<EntryLine> */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function totalDebit(): Money
    {
        return Money::sum(array_map(static fn (EntryLine $line): Money => $line->getDebit(), $this->getLines()));
    }

    public function totalCredit(): Money
    {
        return Money::sum(array_map(static fn (EntryLine $line): Money => $line->getCredit(), $this->getLines()));
    }

    /** The Ledger rewrites a draft: its journal, period, date, document and label. */
    public function ledgerRewrite(Journal $journal, Period $period, \DateTimeImmutable $entryDate, string $document, string $label): void
    {
        $this->journal = $journal;
        $this->period = $period;
        $this->exercise = $period->getExercise();
        $this->entryDate = $entryDate->setTime(0, 0);
        $this->document = $document;
        $this->label = $label;
    }

    /**
     * The Ledger replaces a draft's lines whole (the old ones are deleted with the flush).
     *
     * @param list<EntryLine> $lines
     */
    public function ledgerReplaceLines(array $lines): void
    {
        $this->lines->clear();
        foreach ($lines as $line) {
            $this->lines->add($line);
        }
    }

    /** The Ledger validates: the number of the journal's sequence and the dossier's date. */
    public function ledgerValidate(int $number, \DateTimeImmutable $on): void
    {
        $this->state = EntryState::Validated;
        $this->number = $number;
        $this->validatedOn = $on->setTime(0, 0);
    }
}
