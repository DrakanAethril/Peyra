<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Entity\Document\Document;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\EntryLineRepository;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Audit\AuditTrail;
use App\Service\Document\DocumentReading;
use App\Service\Document\PieceAnomaly;
use App\Service\Purchasing\PurchaseMatching;
use App\Service\Shared\Assistance\Assistance;

/**
 * The one door of a pièce's processing (step 2.2a; design-ux.md §§ 3.5 and 4.3): the entry proposed
 * from its read data, kept on the pièce (made when it first has read data, made again only on
 * « Reprendre l'écriture depuis les données lues »); the controls of its read data and of its
 * entry; the entry written in the brouillard through AccountingGateway - the pièces never name the
 * Ledger - and the pièce posted; the anomalies reported, a rejected pièce set aside. The dossier's
 * behaviour (design-ux.md § 3.4) decides what the screen shows: the proposal to check, an empty
 * grid, or, under « Comptabiliser », the entry already in the brouillard, written as soon as the
 * proposal is whole. Read inside the dossier's context; the caller flushes.
 */
final readonly class PieceProcessing
{
    public function __construct(
        private AccountingGateway $gateway,
        private JournalRepository $journals,
        private EntryLineRepository $lines,
        private ProposedEntryCheck $entryCheck,
        private AssistanceLevels $levels,
        private AuditTrail $trail,
        private PurchaseMatching $matching,
    ) {
    }

    public function behaviour(Document $document): AccountingBehaviour
    {
        return $this->gateway->behaviourFor($document->getDossier(), AccountingModule::Documents);
    }

    /** The proposal the pièce keeps; null until it had read data to propose from. */
    public function proposal(Document $document): ?PieceProposal
    {
        $stored = $document->getProposal();

        return null === $stored ? null : PieceProposal::fromArray($stored);
    }

    /** Its read data changed since its proposal was made (the screen offers to take the entry again). */
    public function isStale(Document $document): bool
    {
        $proposal = $this->proposal($document);

        return null !== $proposal && $proposal->basis !== self::basis($document);
    }

    /**
     * A pièce that has read data and no proposal yet receives one (an arrival, a deposit's read
     * data saved); under « Comptabiliser », a whole proposal goes to the brouillard at once.
     */
    public function arrived(Document $document, User|string $actor): void
    {
        if (!$document->getState()->isOpen()) {
            return;
        }
        if (null === $document->getProposal()) {
            $proposal = $this->propose($document);
            if (null === $proposal) {
                return;
            }
            $document->propose($proposal->toArray());
        }
        $proposal = $this->proposal($document);
        if (null === $proposal || AccountingBehaviour::Post !== $this->behaviour($document)) {
            return;
        }
        $check = $this->check($document, $proposal);
        if (!$check->isWritable() || null === $check->grid) {
            return; // An incomplete proposal (an expense without its account) waits for the student.
        }
        try {
            $outcome = $this->gateway->submit($document->getDossier(), new EntryProposal(AccountingModule::Documents, $check->grid->draft, $proposal->document), $actor);
        } catch (EntryRefused) {
            return; // Refused all the same (a profile that may not enter the journal): the student will.
        }
        if (null !== $outcome->entry) {
            $document->posted($outcome->entry);
            $this->trail->inDossier($document->getDossier(), AuditAction::DocumentPosted, $actor, \sprintf('Pièce %s comptabilisée au brouillard.', $this->name($document)), ['document' => $document->getId()]);
        }
    }

    /** « Reprendre l'écriture depuis les données lues »: the proposal made again from them. */
    public function rebuild(Document $document, User $actor): void
    {
        if (!$document->getState()->isOpen()) {
            throw new \LogicException('A posted or discarded pièce keeps its entry.');
        }
        $proposal = $this->propose($document);
        if (null === $proposal) {
            return;
        }
        $document->propose($proposal->toArray());
        $document->startProcessing();
        $this->trail->inDossier($document->getDossier(), AuditAction::DocumentProposalRebuilt, $actor, \sprintf('Écriture de la pièce %s reprise depuis ses données lues.', $this->name($document)), ['document' => $document->getId()]);
    }

    /**
     * The controls of its read data (the card « Contrôles avant validation »), at the level the
     * dossier is worked at; none for a nature that calls for no entry. A supplier's pièce that
     * reads an order reference adds its three-way match (step 2.4, PurchaseMatching) before the
     * mandatory mentions.
     *
     * @return list<PieceControl>
     */
    public function controls(Document $document): array
    {
        if (null === PieceProposals::side($document->getType(), $document->getThirdParty()?->getKind())) {
            return [];
        }
        $level = $this->levels->for($document->getDossier());
        $controls = PieceControls::check(
            $document->getVatLines(),
            $document->getTotalExcludingTax(),
            $document->getTotalVat(),
            $document->getTotalIncludingTax(),
            $level,
            $document->isPostedWithReserve(),
        );
        $matching = $this->matching->controls($document, Assistance::at($level), $document->isPostedWithReserve());
        if ([] === $matching) {
            return $controls;
        }
        $last = array_pop($controls);

        return null === $last ? $matching : [...$controls, ...$matching, $last];
    }

    /** The entry as the screen holds it, checked as F10 would: in its journal, in the month of its date. */
    public function check(Document $document, PieceProposal $entry): PieceCheck
    {
        return $this->entryCheck->check($document->getDossier(), $entry);
    }

    /**
     * F10: the entry the student made - the proposal corrected, or typed in the empty grid -
     * written in the brouillard through the gateway, and the pièce posted. Refused, nothing is
     * written: its faults, or a failed control of the read data that stops the posting at this
     * level, come back in the check.
     */
    public function post(Document $document, PieceProposal $entry, User $actor): PieceCheck
    {
        if (!$document->getState()->isOpen()) {
            throw new \LogicException('A posted or discarded pièce is not posted again.');
        }
        $check = $this->check($document, $entry);
        foreach ($this->controls($document) as $control) {
            if ($control->blocking) {
                $check->errors['controls'] ??= 'Un contrôle de la pièce n’est pas satisfait : corrigez les données lues, ou signalez l’anomalie.';
            }
        }
        if (!$check->isWritable() || null === $check->grid) {
            return $check;
        }
        try {
            $written = $this->gateway->record($document->getDossier(), new EntryProposal(AccountingModule::Documents, $check->grid->draft, $entry->document), $actor);
        } catch (EntryRefused $refused) {
            $check->errors = [...$check->errors, ...$refused->errors];

            return $check;
        }
        $document->posted($written);
        $this->trail->inDossier($document->getDossier(), AuditAction::DocumentPosted, $actor, \sprintf('Pièce %s comptabilisée au brouillard.', $this->name($document)), ['document' => $document->getId(), 'entry' => $written->getId()]);
        $check->posted = true;

        return $check;
    }

    /** « Signaler une anomalie »: kept on the pièce; « Rejeter la pièce » sets it aside. */
    public function reportAnomaly(Document $document, PieceAnomaly $anomaly, User $actor): void
    {
        $document->reportAnomaly($anomaly);
        $rejected = !$document->getState()->isOpen();
        $this->trail->inDossier(
            $document->getDossier(),
            $rejected ? AuditAction::DocumentRejected : AuditAction::DocumentAnomalyReported,
            $actor,
            \sprintf('%s : %s (%s). %s', $rejected ? 'Pièce '.$this->name($document).' rejetée' : 'Anomalie signalée sur la pièce '.$this->name($document), $anomaly->nature->label(), $anomaly->comment, $anomaly->outcome->label().'.'),
            ['document' => $document->getId(), 'nature' => $anomaly->nature->value, 'outcome' => $anomaly->outcome->value],
        );
    }

    /** The proposal from the pièce's read data as they stand now; null when they call for none. */
    private function propose(Document $document): ?PieceProposal
    {
        $thirdParty = $document->getThirdParty();
        $party = null === $thirdParty ? null : PieceParty::of($thirdParty);
        $side = PieceProposals::side($document->getType(), $thirdParty?->getKind());
        if (null === $side || null === $thirdParty) {
            return null;
        }
        $journal = $this->journals->findByType($document->getDossier(), $side)[0] ?? null;

        return PieceProposals::propose(
            $document->getType(),
            $party,
            $document->getNumber(),
            $document->getDocumentDate(),
            $document->getDueDate(),
            $document->getVatLines(),
            $document->getTotalExcludingTax(),
            $document->getTotalIncludingTax(),
            $journal?->getCode(),
            $this->lines->usualAccountOf($thirdParty, JournalType::Purchases === $side ? 6 : 7),
            self::basis($document),
        );
    }

    /** What the proposal was made from: the read data, as the sheet shows them. */
    public static function basis(Document $document): string
    {
        $reading = DocumentReading::of($document);

        return hash('sha256', (string) json_encode([$reading->type, $reading->thirdParty, $reading->number, $reading->date, $reading->dueDate, $reading->vat, $reading->totalExcludingTax, $reading->totalVat, $reading->totalIncludingTax]));
    }

    private function name(Document $document): string
    {
        return $document->getNumber() ?? $document->getFileName();
    }
}
