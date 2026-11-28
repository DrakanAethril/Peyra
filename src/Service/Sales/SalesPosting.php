<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Identity\User;
use App\Entity\Sales\SalesDocument;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AccountingModule;
use App\Enum\Accounting\JournalType;
use App\Enum\Audit\AuditAction;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Inventory\ArticleRepository;
use App\Service\Accounting\Gateway\AccountingGateway;
use App\Service\Accounting\Gateway\EntryProposal;
use App\Service\Accounting\Ledger\EntryRefused;
use App\Service\Audit\AuditTrail;
use App\Service\Document\Processing\PieceCheck;
use App\Service\Document\Processing\PieceParty;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Document\Processing\ProposedEntryCheck;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How a validated invoicing document reaches the books (step 2.3b; design-ux.md § 3.2 « Factures,
 * Avoirs, Acomptes »): never by itself - it hands its entry (SalesEntries) to AccountingGateway,
 * and the dossier's behaviour decides:
 *
 * - « Comptabiliser »: written in the brouillard at the validation, when the grid's checks let it;
 * - « Proposer »: the fiche shows the entry, the student passes it to the brouillard (`post()`) and
 *   may correct it there before validating it;
 * - « Laisser saisir »: nothing is shown nor written - the student enters it in the sales journal,
 *   and the proposal is what the correction compares with (step 2.11).
 *
 * The entry written is linked to the document; under « Laisser saisir » the fiche reads the
 * entries of the document's number. Read inside the dossier's context.
 */
final readonly class SalesPosting
{
    public function __construct(
        private AccountingGateway $gateway,
        private JournalRepository $journals,
        private ArticleRepository $articles,
        private EntryRepository $entries,
        private ProposedEntryCheck $entryCheck,
        private AuditTrail $trail,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function behaviour(SalesDocument $document): AccountingBehaviour
    {
        return $this->gateway->behaviourFor($document->getDossier(), AccountingModule::Sales);
    }

    /**
     * The entry a validated invoicing document asks for; null for another document, a draft, a
     * dossier without a sales journal or a customer kept on no account.
     */
    public function proposal(SalesDocument $document): ?PieceProposal
    {
        $type = $document->getType();
        $number = $document->getNumber();
        $party = PieceParty::of($document->getCustomer());
        if (!$type->isInvoicing() || null === $number || null === $party) {
            return null;
        }
        $dossier = $document->getDossier();
        $journal = $this->salesJournal($document);
        if (null === $journal) {
            return null;
        }
        $totals = $document->getTotals();
        $revenue = [];
        foreach ($document->getLines() as $i => $line) {
            $article = null === $line->article ? null : $this->articles->findOneByCode($dossier, $line->article);
            $revenue[] = ['account' => $article?->getRevenueAccount() ?? SalesEntries::DEFAULT_REVENUE, 'amount' => $totals->lineAmounts[$i]];
        }

        return SalesEntries::propose($type, $party, $document->getCustomer()->getCode(), $number, $document->getDate(), $document->getDueDate(), $revenue, $totals, $document->isVatOnReceipts(), $journal->getCode());
    }

    /** The dossier's sales journal (its first, by code): where an invoicing document's entry goes. */
    public function salesJournal(SalesDocument $document): ?Journal
    {
        return $this->journals->findByType($document->getDossier(), JournalType::Sales)[0] ?? null;
    }

    /** The proposal checked as F10 would write it. */
    public function check(SalesDocument $document, PieceProposal $proposal): PieceCheck
    {
        return $this->entryCheck->check($document->getDossier(), $proposal);
    }

    /**
     * Just validated (SalesDocuments::validate()): under « Comptabiliser », its entry goes to the
     * brouillard if the grid's checks let it - else it waits on the fiche, as under « Proposer ».
     * Flushed here.
     */
    public function validated(SalesDocument $document, User|string $by): void
    {
        if (AccountingBehaviour::Post !== $this->behaviour($document)) {
            return;
        }
        $proposal = $this->proposal($document);
        if (null === $proposal) {
            return;
        }
        $check = $this->check($document, $proposal);
        if (!$check->isWritable() || null === $check->grid) {
            return;
        }
        try {
            $outcome = $this->gateway->submit($document->getDossier(), new EntryProposal(AccountingModule::Sales, $check->grid->draft, $proposal->document), $by);
        } catch (EntryRefused) {
            return; // Refused all the same (a profile that may not enter the sales journal): it waits on the fiche.
        }
        if (null !== $outcome->entry) {
            $this->linked($document, $outcome->entry, $by);
            $this->entityManager->flush();
        }
    }

    /**
     * « Passer l'écriture au brouillard »: the proposal written in the brouillard through the
     * gateway, under « Proposer » - or « Comptabiliser » when it could not be written at the
     * validation. Refused, nothing is written and the check says why. Flushed here.
     */
    public function post(SalesDocument $document, User $actor): PieceCheck
    {
        if (null !== $document->getEntry() || AccountingBehaviour::LetEnter === $this->behaviour($document)) {
            throw new \LogicException('This document’s entry is not posted from its fiche.');
        }
        $proposal = $this->proposal($document) ?? throw new \LogicException('This document asks for no entry.');
        $check = $this->check($document, $proposal);
        if (!$check->isWritable() || null === $check->grid) {
            return $check;
        }
        try {
            $entry = $this->gateway->record($document->getDossier(), new EntryProposal(AccountingModule::Sales, $check->grid->draft, $proposal->document), $actor);
        } catch (EntryRefused $refused) {
            $check->errors = [...$check->errors, ...$refused->errors];

            return $check;
        }
        $this->linked($document, $entry, $actor);
        $this->entityManager->flush();
        $check->posted = true;

        return $check;
    }

    /**
     * The entries of the document: the one its posting wrote, else - the student entered it
     * themselves - those whose pièce is its number.
     *
     * @return list<Entry>
     */
    public function entries(SalesDocument $document): array
    {
        $entry = $document->getEntry();
        if (null !== $entry) {
            return [$entry];
        }
        $number = $document->getNumber();

        return null === $number ? [] : $this->entries->findByDocumentReference($document->getDossier(), $number);
    }

    private function linked(SalesDocument $document, Entry $entry, User|string $by): void
    {
        $document->linkEntry($entry);
        $document->recordHistory('posted', $document->getDossier()->getDossierDate(), $by instanceof User ? $by->getDisplayName() : $by, \sprintf('Écriture au brouillard du journal %s', $entry->getJournal()->getCode()));
        $this->trail->inDossier($document->getDossier(), AuditAction::SalesDocumentPosted, $by, \sprintf('%s comptabilisé au brouillard.', $document->getNumber() ?? ''), ['document' => $document->getId(), 'entry' => $entry->getId()]);
    }
}
