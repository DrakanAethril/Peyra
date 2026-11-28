<?php

declare(strict_types=1);

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\JournalType;
use App\Enum\Document\AnomalyNature;
use App\Enum\Document\AnomalyOutcome;
use App\Enum\Document\DocumentType;
use App\Repository\Accounting\AccountRepository;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Document\DocumentRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Entry\GridLine;
use App\Service\Accounting\Entry\PendingEntries;
use App\Service\Document\DocumentReading;
use App\Service\Document\Documents;
use App\Service\Document\PieceAnomaly;
use App\Service\Document\Processing\PieceProcessing;
use App\Service\Document\Processing\PieceProposal;
use App\Service\Shared\PostValue;
use App\Service\Shared\Storage\DossierFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A pièce's processing screen (gabarit P, design-ux.md §§ 3.5 and 4.3; maquette 1): the viewer on
 * the left; on the right the read data, then the entry - proposed from them and kept on the pièce,
 * an empty grid, or already in the brouillard, as the dossier's behaviour says (PieceProcessing) -,
 * then the card « Contrôles avant validation ». F10 saves the read data and, when the grid holds
 * an entry, writes it in the brouillard through the gateway and opens the next pièce; without an
 * entry, the pièce stays « en cours ». « Reprendre l'écriture depuis les données lues » makes the
 * proposal again; « Signaler une anomalie » keeps one, and may reject the pièce. Whoever may change
 * the dossier works an open pièce. Its file is served here, inline or as a download, to whoever
 * opens the dossier: the storage is private.
 */
#[Route('/d/{dossier}/documents', requirements: ['dossier' => '\d+'])]
final class DocumentController extends AbstractController
{
    /** Empty lines the grid offers under the entry's own. */
    private const int SPARE_LINES = 1;

    #[Route('/{id}', name: 'app_document', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, ThirdPartyRepository $thirdParties, JournalRepository $journals, AccountRepository $accounts, EntryRepository $entries, Documents $door, PieceProcessing $processing, AssistanceLevels $levels, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $documents->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such pièce.');
        $canEdit = $this->isGranted(DossierVoter::EDIT, $dossier) && $document->getState()->isOpen();
        $reading = DocumentReading::of($document);
        $behaviour = $processing->behaviour($document);
        $proposal = $processing->proposal($document);
        $entry = $this->shownEntry($document, $behaviour, $proposal);
        $check = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$canEdit || !$this->isCsrfTokenValid('document-'.$document->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $this->read($request, $reading);
            $entry = $this->typedEntry($request);
            if ($door->saveReading($document, $reading, $me)) {
                if ('rebuild' === $request->request->getString('_action')) {
                    $processing->rebuild($document, $me);
                    $entityManager->flush();
                    $this->addFlash('status', 'Écriture reprise depuis les données lues.');

                    return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
                }
                if ([] === $entry->group()->typedLines()) {
                    $processing->arrived($document, $me);
                    $document->startProcessing();
                    $entityManager->flush();
                    $this->addFlash('status', $document->getState()->isOpen() ? 'Données lues enregistrées. L’écriture reste à saisir.' : 'Données lues enregistrées. Écriture enregistrée au brouillard.');

                    return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
                }
                $check = $processing->post($document, $entry, $me);
                if ($check->posted) {
                    $entityManager->flush();
                    $written = $document->getEntry();
                    $this->addFlash('status', \sprintf('Écriture enregistrée au brouillard · %s · %s', $written?->getJournal()->getCode() ?? '', $written?->getDocument() ?? ''));
                    $next = $documents->findNextOpen($dossier, $document);

                    return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => ($next ?? $document)->getId()], Response::HTTP_SEE_OTHER);
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } elseif ($canEdit && null !== $entry && [] !== $entry->lines) {
            $check = $processing->check($document, $entry);
        }

        $number = $document->getNumber();
        $written = $document->getEntry();

        return $this->render('document/show.html.twig', [
            'dossier' => $dossier,
            'document' => $document,
            'reading' => $reading,
            'can_edit' => $canEdit,
            'behaviour' => $behaviour,
            'entry_form' => $canEdit ? $this->withSpareLines($entry ?? $this->blankEntry($document, $proposal)) : null,
            'proposed' => $canEdit && AccountingBehaviour::LetEnter !== $behaviour && null !== $proposal && !$request->isMethod('POST'),
            'stale' => $canEdit && $processing->isStale($document),
            'check' => $check,
            'controls' => $processing->controls($document),
            'level_label' => $levels->for($dossier)->label(),
            'account_labels' => $this->accountLabels($dossier, $accounts, $thirdParties, $entry),
            'journal_options' => array_map(static fn ($j): array => ['value' => $j->getCode(), 'label' => $j->getCode().' · '.$j->getLabel()], array_values(array_filter($journals->findByDossier($dossier), static fn ($j): bool => \in_array($j->getType(), [JournalType::Purchases, JournalType::Sales, JournalType::General], true)))),
            'written' => $written,
            'entries' => null === $number ? [] : array_values(array_filter($entries->findByDocumentReference($dossier, $number), static fn ($one): bool => $one !== $written)),
            'type_options' => array_map(static fn (DocumentType $t): array => ['value' => $t->value, 'label' => $t->label()], DocumentType::cases()),
            'third_party_options' => array_map(static fn ($t): array => ['value' => (string) $t->getId(), 'label' => $t->getCode().' · '.$t->getName()], $thirdParties->findByDossier($dossier)),
            'nature_options' => array_map(static fn (AnomalyNature $n): array => ['value' => $n->value, 'label' => $n->label()], AnomalyNature::cases()),
            'outcome_options' => array_map(static fn (AnomalyOutcome $o): array => ['value' => $o->value, 'label' => $o->label()], AnomalyOutcome::cases()),
            'anomaly_error' => null,
        ], new Response(status: $status));
    }

    /** « Signaler une anomalie » (design-ux.md § 4.3): its nature, the comment that justifies it, what is done. */
    #[Route('/{id}/anomaly', name: 'app_document_anomaly', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function anomaly(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, PieceProcessing $processing, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $documents->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such pièce.');
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$document->getState()->isOpen() || !$this->isCsrfTokenValid('document-'.$document->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $nature = AnomalyNature::tryFrom(PostValue::trimmed($request, 'nature'));
        $outcome = AnomalyOutcome::tryFrom(PostValue::trimmed($request, 'outcome'));
        $comment = mb_substr(PostValue::trimmed($request, 'comment'), 0, 500);
        if (null === $nature || null === $outcome || '' === $comment) {
            $this->addFlash('status', 'L’anomalie n’est pas signalée : choisissez sa nature et la suite donnée, et justifiez-la d’un commentaire.');

            return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
        }
        $processing->reportAnomaly($document, new PieceAnomaly($nature, $comment, $outcome, $dossier->getDossierDate()), $me);
        $entityManager->flush();
        $this->addFlash('status', AnomalyOutcome::Reject === $outcome ? 'Pièce rejetée : elle passe dans les pièces écartées.' : 'Anomalie signalée : la pièce sera comptabilisée avec réserve.');

        return $this->redirectToRoute('app_document', ['dossier' => $dossier->getId(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/file', name: 'app_document_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(Dossier $dossier, int $id, Request $request, DocumentRepository $documents, DossierFiles $files): Response
    {
        $document = $documents->findOneInDossier($dossier, $id) ?? throw new NotFoundHttpException('No such pièce.');
        $dossierId = (int) $dossier->getId();
        $key = $document->getFileKey();
        if (!$files->exists($dossierId, $key)) {
            throw new NotFoundHttpException('The pièce’s file is missing.');
        }
        $response = new StreamedResponse(static function () use ($files, $dossierId, $key): void {
            $stream = $files->readStream($dossierId, $key);
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $document->getMediaType());
        $response->headers->set('Content-Length', (string) $files->size($dossierId, $key));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        $disposition = $request->query->getBoolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE;
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->getFileName());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition($disposition, $document->getFileName(), '' === trim($fallback, '-.') ? 'piece' : $fallback));

        return $response;
    }

    /**
     * What the entry part shows on arrival: under « Proposer » the proposal kept on the pièce; under
     * « Laisser saisir » nothing (an empty grid under the proposal's head); under « Comptabiliser »,
     * a pièce not yet posted (its proposal incomplete) shows its proposal to finish.
     */
    private function shownEntry(Document $document, AccountingBehaviour $behaviour, ?PieceProposal $proposal): ?PieceProposal
    {
        if (null === $proposal || AccountingBehaviour::LetEnter === $behaviour) {
            return null;
        }

        return $proposal;
    }

    /** The empty grid: the head of the entry from the proposal when there is one, no line. */
    private function blankEntry(Document $document, ?PieceProposal $proposal): PieceProposal
    {
        if (null !== $proposal) {
            return new PieceProposal($proposal->journal, $proposal->date, $proposal->document, $proposal->label, []);
        }

        return new PieceProposal('', $document->getDocumentDate()?->format('Y-m-d'), $document->getNumber() ?? '', '', []);
    }

    private function withSpareLines(PieceProposal $entry): PieceProposal
    {
        $lines = $entry->lines;
        for ($spare = 0; $spare < self::SPARE_LINES; ++$spare) {
            $lines[] = new GridLine();
        }

        return new PieceProposal($entry->journal, $entry->date, $entry->document, $entry->label, $lines, $entry->basis);
    }

    /** The entry as the form posts it: its head, then its lines in the order of the page. */
    private function typedEntry(Request $request): PieceProposal
    {
        $date = PostValue::trimmed($request, 'entry_date');
        $lines = [];
        foreach (\array_slice(PostValue::rows($request, 'lines'), 0, PendingEntries::MAX_LINES) as $row) {
            $due = $row['due'] ?? '';
            $lines[] = new GridLine(
                mb_substr($row['account'] ?? '', 0, 17),
                mb_substr($row['thirdParty'] ?? '', 0, 17),
                '',
                self::cents($row['debit'] ?? ''),
                self::cents($row['credit'] ?? ''),
                1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
            );
        }

        return new PieceProposal(
            mb_substr(PostValue::trimmed($request, 'entry_journal'), 0, 10),
            1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            mb_substr(PostValue::trimmed($request, 'entry_document'), 0, 60),
            mb_substr(PostValue::trimmed($request, 'entry_label'), 0, 250),
            array_values(array_filter($lines, static fn (GridLine $line): bool => !$line->isBlank())),
        );
    }

    /** An amount of the grid as Px:AmountInput posts it, in whole cents; anything else is nothing. */
    private static function cents(string $posted): int
    {
        return 1 === preg_match('/^\d{1,15}$/', $posted) ? (int) $posted : 0;
    }

    /**
     * The label under each account the grid shows (the Intitulé column), as the picker writes it:
     * an account's label, a third party's name.
     *
     * @return array<string, string>
     */
    private function accountLabels(Dossier $dossier, AccountRepository $accounts, ThirdPartyRepository $thirdParties, ?PieceProposal $entry): array
    {
        $wanted = [];
        foreach ($entry->lines ?? [] as $line) {
            if ('' !== $line->account) {
                $wanted[strtoupper($line->account)] = true;
            }
        }
        if ([] === $wanted) {
            return [];
        }
        $labels = [];
        foreach ($accounts->findPlan($dossier) as $account) {
            if (isset($wanted[$account->getNumber()])) {
                $labels[$account->getNumber()] = $account->getLabel();
            }
        }
        foreach ($thirdParties->findByDossier($dossier) as $thirdParty) {
            $auxiliary = $thirdParty->getAuxiliaryCode();
            if (null !== $auxiliary && isset($wanted[$auxiliary])) {
                $labels[$auxiliary] = $thirdParty->getName();
            }
        }

        return $labels;
    }

    private function read(Request $request, DocumentReading $reading): void
    {
        foreach (['type', 'thirdParty', 'number', 'date', 'dueDate', 'orderReference'] as $field) {
            $reading->{$field} = PostValue::trimmed($request, $field);
        }
        for ($index = 0; $index < DocumentReading::VAT_ROWS; ++$index) {
            $reading->vat[$index] = [
                'rate' => PostValue::trimmed($request, 'vat_rate_'.$index),
                'base' => PostValue::cents($request, 'vat_base_'.$index),
                'vat' => PostValue::cents($request, 'vat_amount_'.$index),
            ];
        }
        $reading->totalExcludingTax = PostValue::cents($request, 'totalExcludingTax');
        $reading->totalVat = PostValue::cents($request, 'totalVat');
        $reading->totalIncludingTax = PostValue::cents($request, 'totalIncludingTax');
    }
}
