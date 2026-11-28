<?php

declare(strict_types=1);

namespace App\Controller\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\Article;
use App\Entity\Sales\SalesDocument;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Sales\SalesDocumentRepository;
use App\Repository\ThirdParty\ThirdPartyRepository;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Purchasing\ReceiptProgress;
use App\Service\Purchasing\Receipts;
use App\Service\Sales\SalesDocumentDraft;
use App\Service\Sales\SalesDocuments;
use App\Service\Sales\SalesPosting;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The fiche of a commercial document (step 2.3, gabarit F; design-ux.md § 3.2): the chain at its
 * head (devis → commande → livraison → facture → avoir), the customer and the dates, the lines in a
 * grid - an article's reference fills its designation, unit, price and rate -, the HT / TVA / TTC
 * foot, the history. A draft is saved with F10 (SALES_ENTER), validated with Ctrl Entrée after the
 * confirmation bar (SALES_VALIDATE) and then never changes: « Transformer en… », « Créer un avoir »,
 * « Envoyer » (simulated) and the PDF are what is left. Every gesture goes through SalesDocuments.
 * An invoice, a credit note or a deposit invoice then shows its entry (step 2.3b, SalesPosting): the
 * one written, the one proposed (« Passer l'écriture au brouillard »), or the journal to enter it in.
 *
 * Step 2.4: the same fiche serves the purchasing side under `/purchases` - a price request, a
 * supplier order, its receipts - with the rights of the area Achats (PURCHASES_*). An order shows
 * what its receipts received; a receipt is opened from its order on what is left, and shows the
 * order beside it (the gaps flagged at level G).
 */
#[Route('/d/{dossier}/{side}', requirements: ['dossier' => '\d+', 'side' => 'sales|purchases'])]
final class SalesDocumentController extends AbstractController
{
    #[Route('/{type}/new', name: 'app_sales_document_new', requirements: ['type' => SalesDocumentsController::TYPES], methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, string $side, string $type, Request $request, SalesDocuments $documents, SalesPosting $posting, Receipts $receipts, AssistanceLevels $levels, ThirdPartyRepository $thirdParties, ArticleRepository $articles, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $kind = SalesDocumentType::from($type);
        if ($kind->side() !== $side) {
            throw new NotFoundHttpException('No such document on this side.');
        }
        $this->allow($dossier, self::right($kind, 'ENTER'));
        if (\in_array($kind, [SalesDocumentType::CreditNote, SalesDocumentType::Deposit, SalesDocumentType::Receipt], true)) {
            throw new NotFoundHttpException('A credit note is made from its invoice, a deposit invoice or a receipt from its order.');
        }
        $draft = $documents->blank($dossier);
        $draft->customer = strtoupper(QueryValue::trimmed($request, 'customer'));
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'sales-new-'.$dossier->getId());
            $fields = $documents->check($draft, $dossier, $kind);
            if (null !== $fields) {
                $document = $documents->create($dossier, $kind, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Brouillon de %s enregistré.', mb_strtolower($kind->label())));

                return $this->toDocument($document);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('sales/document.html.twig', $this->view($dossier, $kind, null, $draft, $documents, $posting, $receipts, $levels, $thirdParties, $articles), new Response(status: $status));
    }

    #[Route('/documents/{id}', name: 'app_sales_document', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Dossier $dossier, string $side, int $id, Request $request, SalesDocuments $documents, SalesPosting $posting, Receipts $receipts, AssistanceLevels $levels, SalesDocumentRepository $repository, ThirdPartyRepository $thirdParties, ArticleRepository $articles, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $this->document($dossier, $side, $id, $repository);
        $draft = SalesDocumentDraft::of($document);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->allow($dossier, self::right($document->getType(), 'ENTER'));
            if (!$document->isDraft()) {
                throw new NotFoundHttpException('A validated document never changes.');
            }
            $this->read($request, $draft, 'sales-'.$document->getId());
            $fields = $documents->check($draft, $dossier, $document->getType());
            if (null !== $fields) {
                $documents->update($document, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', 'Brouillon enregistré.');

                return $this->toDocument($document);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('sales/document.html.twig', $this->view($dossier, $document->getType(), $document, $draft, $documents, $posting, $receipts, $levels, $thirdParties, $articles), new Response(status: $status));
    }

    #[Route('/documents/{id}/validate', name: 'app_sales_document_validate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validate(Dossier $dossier, string $side, int $id, Request $request, SalesDocuments $documents, SalesDocumentRepository $repository, #[CurrentUser] User $me): Response
    {
        $document = $this->gesture($dossier, $side, $id, $request, $repository, 'VALIDATE', 'sales-validate-');
        $refusals = $documents->validationRefusals($document);
        if ([] === $refusals) {
            $documents->validate($document, $me);
            $this->addFlash('status', \sprintf('Validation faite : numéro %s.', $document->getNumber() ?? ''));
        } else {
            $this->addFlash('status', 'Le document n’est pas validé : '.implode(' ', $refusals));
        }

        return $this->toDocument($document);
    }

    #[Route('/documents/{id}/delete', name: 'app_sales_document_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, string $side, int $id, Request $request, SalesDocuments $documents, SalesDocumentRepository $repository, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $this->gesture($dossier, $side, $id, $request, $repository, 'ENTER', 'sales-delete-');
        if (!$document->isDraft()) {
            throw new NotFoundHttpException('A validated document is never deleted.');
        }
        $type = $document->getType();
        $documents->delete($document, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Brouillon de %s supprimé.', mb_strtolower($type->label())));

        return $this->redirectToRoute('app_sales_documents', ['dossier' => $dossier->getId(), 'side' => $side, 'type' => $type->value], Response::HTTP_SEE_OTHER);
    }

    #[Route('/documents/{id}/transform', name: 'app_sales_document_transform', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function transform(Dossier $dossier, string $side, int $id, Request $request, SalesDocuments $documents, SalesDocumentRepository $repository, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $this->gesture($dossier, $side, $id, $request, $repository, 'ENTER', 'sales-transform-');
        $into = SalesDocumentType::tryFrom(PostValue::trimmed($request, 'into')) ?? throw new NotFoundHttpException('No such type.');
        $refusal = $documents->transformRefusal($document, $into);
        if (null !== $refusal) {
            $this->addFlash('status', $refusal);

            return $this->toDocument($document);
        }
        $percent = null;
        if (SalesDocumentType::Deposit === $into) {
            $percent = self::percent(PostValue::trimmed($request, 'percent'));
            if (null === $percent) {
                $this->addFlash('status', 'Un acompte se donne en pourcentage de la commande : de 0,01 à 100 %.');

                return $this->toDocument($document);
            }
        }
        $next = $documents->transform($document, $into, $me, $percent);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Brouillon de %s ouvert depuis %s.', mb_strtolower($into->label()), $document->getNumber() ?? ''));

        return $this->toDocument($next);
    }

    /** « Passer l'écriture au brouillard » (step 2.3b): the proposal written through the gateway. */
    #[Route('/documents/{id}/post', name: 'app_sales_document_post', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function post(Dossier $dossier, string $side, int $id, Request $request, SalesPosting $posting, SalesDocumentRepository $repository, #[CurrentUser] User $me): Response
    {
        $document = $this->gesture($dossier, $side, $id, $request, $repository, 'VALIDATE', 'sales-post-');
        if (null !== $document->getEntry() || null === $posting->proposal($document) || AccountingBehaviour::LetEnter === $posting->behaviour($document)) {
            throw new NotFoundHttpException('This document’s entry is not posted from here.');
        }
        $check = $posting->post($document, $me);
        $this->addFlash('status', $check->posted
            ? 'L’écriture est au brouillard : vérifiez-la, corrigez-la au besoin, puis validez-la.'
            : 'L’écriture n’est pas passée : '.implode(' ', array_unique(array_values($check->errors))));

        return $this->toDocument($document);
    }

    #[Route('/documents/{id}/send', name: 'app_sales_document_send', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function send(Dossier $dossier, string $side, int $id, Request $request, SalesDocuments $documents, SalesDocumentRepository $repository, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $document = $this->gesture($dossier, $side, $id, $request, $repository, 'VALIDATE', 'sales-send-');
        if ($document->isDraft() || !$document->getType()->isSent()) {
            throw new NotFoundHttpException('A draft or a receipt is not sent.');
        }
        $documents->send($document, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('%s est parti chez %s : l’envoi figure dans l’historique.', $document->getNumber() ?? '', $document->getThirdParty()->getName()));

        return $this->toDocument($document);
    }

    #[Route('/documents/{id}.pdf', name: 'app_sales_document_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(Dossier $dossier, string $side, int $id, SalesDocumentRepository $repository, SalesDocuments $documents, PdfRenderer $renderer): Response
    {
        $document = $this->document($dossier, $side, $id, $repository);
        $name = (null === $document->getNumber() ? $document->getType()->label().'-brouillon' : $document->getNumber()).'.pdf';

        return new Response($renderer->renderTemplate('sales/pdf/document.pdf.html.twig', self::paper($dossier, $document, $documents)), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name, 'document.pdf'),
        ]);
    }

    /**
     * What the paper prints: the company, the customer as frozen at the validation (as they are now
     * for a draft), the document and its totals.
     *
     * @return array<string, mixed>
     */
    public static function paper(Dossier $dossier, SalesDocument $document, SalesDocuments $documents): array
    {
        return [
            'company' => $dossier->getCompany(),
            'document' => $document,
            'customer' => $document->getCustomerPrint() ?? SalesDocuments::print($document->getCustomer()),
            'totals' => $documents->totals($document),
        ];
    }

    /** @return array<string, mixed> */
    private function view(Dossier $dossier, SalesDocumentType $type, ?SalesDocument $document, SalesDocumentDraft $draft, SalesDocuments $documents, SalesPosting $posting, Receipts $receipts, AssistanceLevels $levels, ThirdPartyRepository $thirdParties, ArticleRepository $articles): array
    {
        $kind = $type->partyKind();
        $parties = array_values(array_filter($thirdParties->findByDossier($dossier), static fn (ThirdParty $t): bool => $kind === $t->getKind()));
        $catalogue = [];
        foreach ($articles->findByDossier($dossier) as $article) {
            $price = $type->isPurchase() ? $article->getPurchasePrice() : $article->getSalePrice();
            if (null !== $price) {
                $catalogue[$article->getCode()] = self::articleRow($article, FrenchFormat::amount($price));
            }
        }
        $editable = null === $document || ($document->isDraft() && $this->isGranted(self::right($type, 'ENTER'), $dossier));

        return [
            'dossier' => $dossier,
            'doc_type' => $type,
            'document' => $document,
            'draft' => $draft,
            'editable' => $editable,
            'customer_options' => array_map(static fn (ThirdParty $t): array => ['value' => $t->getCode(), 'label' => $t->getCode().' · '.$t->getName()], $parties),
            'rights' => $type->isPurchase() ? 'PURCHASES' : 'SALES',
            'catalogue' => $catalogue,
            'rates' => $documents->ratesInForce($dossier),
            'chain_steps' => null === $document ? [] : $this->chainSteps($dossier, $document, $documents->chain($document)),
            'refusals' => null !== $document && $document->isDraft() ? $documents->validationRefusals($document) : [],
            'transforms' => null === $document || $document->isDraft() ? [] : array_values(array_filter($type->transformsInto(), static fn (SalesDocumentType $into): bool => null === $documents->transformRefusal($document, $into))),
            'blank_line' => SalesDocumentDraft::blankLine(),
            'totals' => null === $document ? null : $documents->totals($document),
            'posting' => null === $document ? null : $this->posting($document, $posting),
            'receiving' => null === $document ? null : self::receiving($document, $receipts, Assistance::at($levels->for($dossier))),
        ];
    }

    /**
     * The card « Réception » (step 2.4): for a validated supplier order, each line ordered, received
     * and left, its status and its receipts; for a receipt, its order's lines as they stood before it
     * beside what it receives, and - at level G - where it departs from them.
     *
     * @return array{order: SalesDocument, rows: list<array{line: \App\Service\Sales\SalesLine, ordered: BigDecimal, received: BigDecimal, remaining: BigDecimal, this: ?BigDecimal}>, status: ?\App\Enum\Purchasing\ReceiptStatus, receipts: list<SalesDocument>, gaps: list<string>}|null
     */
    private static function receiving(SalesDocument $document, Receipts $receipts, Assistance $assistance): ?array
    {
        $type = $document->getType();
        $order = match (true) {
            SalesDocumentType::PurchaseOrder === $type && !$document->isDraft() => $document,
            SalesDocumentType::Receipt === $type => $document->getSource(),
            default => null,
        };
        if (null === $order) {
            return null;
        }
        $isReceipt = SalesDocumentType::Receipt === $type;
        $progress = $receipts->progress($order, $isReceipt ? $document : null);
        $taken = [];
        if ($isReceipt) {
            foreach ($document->getLines() as $line) {
                if (null !== $line->sourceLine) {
                    $taken[$line->sourceLine] = ($taken[$line->sourceLine] ?? BigDecimal::zero())->plus($line->quantity);
                }
            }
        }
        $rows = [];
        foreach ($progress as $row) {
            $rows[] = ['line' => $row->line, 'ordered' => $row->line->quantity, 'received' => $row->received, 'remaining' => $row->remaining, 'this' => $isReceipt ? ($taken[$row->index] ?? BigDecimal::zero()) : null];
        }

        return [
            'order' => $order,
            'rows' => $rows,
            'status' => $isReceipt ? null : ReceiptProgress::status($progress),
            'receipts' => $isReceipt ? [] : $receipts->of($order),
            'gaps' => $isReceipt && $assistance->flagsQuantityGaps() ? ReceiptProgress::gaps($progress, $document->getLines()) : [],
        ];
    }

    /**
     * The card « Écriture » of a validated invoicing document (step 2.3b): the behaviour, the entries
     * it led to and, until one exists, the proposal with its check - never under « Laisser saisir »,
     * whose proposal is the correction's.
     *
     * @return array<string, mixed>|null
     */
    private function posting(SalesDocument $document, SalesPosting $posting): ?array
    {
        if ($document->isDraft() || !$document->getType()->isInvoicing()) {
            return null;
        }
        $behaviour = $posting->behaviour($document);
        $entries = $posting->entries($document);
        $proposal = null;
        $check = null;
        if ([] === $entries && AccountingBehaviour::LetEnter !== $behaviour) {
            $proposal = $posting->proposal($document);
            $check = null === $proposal ? null : $posting->check($document, $proposal);
        }

        return ['behaviour' => $behaviour, 'entries' => $entries, 'proposal' => $proposal, 'check' => $check, 'journal' => $posting->salesJournal($document)?->getCode()];
    }

    /** A deposit's percentage of the order, typed « 30 » or « 12,5 »: above 0, 100 at most, two decimals. */
    private static function percent(string $typed): ?BigDecimal
    {
        $text = str_replace([',', ' ', '%'], ['.', '', ''], $typed);
        if (1 !== preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text)) {
            return null;
        }
        try {
            $percent = BigDecimal::of($text);
        } catch (MathException) {
            return null;
        }

        return $percent->isPositive() && !$percent->isGreaterThan(100) ? $percent : null;
    }

    /**
     * The chain at the head of the fiche (design-ux.md § 3.2): every document of it - this one current,
     * the others links -, then the steps still to come up to the invoice.
     *
     * @param list<SalesDocument> $chain
     *
     * @return list<array{label: string, href?: string, state: string}>
     */
    private function chainSteps(Dossier $dossier, SalesDocument $document, array $chain): array
    {
        $steps = [];
        foreach ($chain as $link) {
            $steps[] = [
                'label' => $link->getType()->label().' '.($link->getNumber() ?? '(brouillon)'),
                'href' => $this->generateUrl('app_sales_document', ['dossier' => $dossier->getId(), 'side' => $link->getType()->side(), 'id' => $link->getId()]),
                'state' => $link === $document ? 'current' : 'done',
            ];
        }
        $order = $document->getType()->chainOrder();
        $last = $order[0];
        foreach ($chain as $link) {
            if (SalesDocumentType::Deposit !== $link->getType()) {
                $last = $link->getType();
            }
        }
        if (!$last->isInvoicing() && \in_array($last, $order, true)) {
            foreach (\array_slice($order, (int) array_search($last, $order, true) + 1) as $toCome) {
                $steps[] = ['label' => $toCome->label(), 'state' => 'todo'];
            }
        }

        return $steps;
    }

    /** @return array{designation: string, unit: string, unitPrice: string, rate: string} */
    private static function articleRow(Article $article, string $price): array
    {
        return [
            'designation' => $article->getDesignation(),
            'unit' => $article->getUnit(),
            'unitPrice' => $price,
            'rate' => str_replace('.', ',', $article->getVatRate() ?? ''),
        ];
    }

    /** The right a gesture asks, by the document's side: SALES_ENTER, PURCHASES_VALIDATE… */
    private static function right(SalesDocumentType $type, string $level): string
    {
        return ($type->isPurchase() ? 'PURCHASES_' : 'SALES_').$level;
    }

    private function toDocument(SalesDocument $document): Response
    {
        return $this->redirectToRoute('app_sales_document', ['dossier' => $document->getDossier()->getId(), 'side' => $document->getType()->side(), 'id' => $document->getId()], Response::HTTP_SEE_OTHER);
    }

    private function read(Request $request, SalesDocumentDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, PostValue::trimmed($request, '_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
        $draft->customer = PostValue::trimmed($request, 'customer');
        $draft->date = PostValue::trimmed($request, 'date');
        $draft->dueDate = PostValue::trimmed($request, 'dueDate');
        $draft->reference = PostValue::trimmed($request, 'reference');
        $draft->cashDiscount = PostValue::trimmed($request, 'cashDiscount');
        $draft->vatOnReceipts = '1' === PostValue::trimmed($request, 'vatOnReceipts');
        $draft->readLines(PostValue::rows($request, 'lines'));
    }

    private function allow(Dossier $dossier, string $right): void
    {
        if (!$this->isGranted($right, $dossier)) {
            throw new NotFoundHttpException('Not allowed by the profile.');
        }
    }

    private function document(Dossier $dossier, string $side, int $id, SalesDocumentRepository $repository): SalesDocument
    {
        $document = $repository->find($id);
        if (null === $document || $document->getDossier() !== $dossier || $document->getType()->side() !== $side) {
            throw new NotFoundHttpException('No such document.');
        }
        $this->allow($dossier, self::right($document->getType(), 'READ'));

        return $document;
    }

    private function gesture(Dossier $dossier, string $side, int $id, Request $request, SalesDocumentRepository $repository, string $level, string $token): SalesDocument
    {
        $document = $this->document($dossier, $side, $id, $repository);
        $this->allow($dossier, self::right($document->getType(), $level));
        if (!$this->isCsrfTokenValid($token.$document->getId(), PostValue::trimmed($request, '_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }

        return $document;
    }
}
