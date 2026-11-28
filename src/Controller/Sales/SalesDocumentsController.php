<?php

declare(strict_types=1);

namespace App\Controller\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesDocument;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Sales\SalesDocumentRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Purchasing\Receipts;
use App\Service\Shared\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ventes › Devis · Commandes · Livraisons and Ventes › Factures · Avoirs · Acomptes (step 2.3, gabarit L):
 * one list per type, the chips of its tab's family; drafts first, then the validated ones by
 * number, newest first. A document opens its fiche (SalesDocumentController).
 *
 * Step 2.4: Achats et stocks › Commandes · Réceptions is the same list under `/purchases` (demandes
 * de prix, commandes, réceptions), with an order's receipt status.
 */
#[Route('/d/{dossier}/{side}', requirements: ['dossier' => '\d+', 'side' => 'sales|purchases'])]
final class SalesDocumentsController extends AbstractController
{
    public const string TYPES = 'quote|order|delivery|invoice|credit_note|deposit|price_request|purchase_order|receipt';

    #[Route('/{type}', name: 'app_sales_documents', requirements: ['type' => self::TYPES], methods: ['GET'])]
    public function index(Dossier $dossier, string $side, string $type, Request $request, SalesDocumentRepository $documents, Receipts $receipts): Response
    {
        $shown = SalesDocumentType::from($type);
        if ($shown->side() !== $side || !$this->isGranted($shown->isPurchase() ? DossierRightVoter::PURCHASES_READ : DossierRightVoter::SALES_READ, $dossier)) {
            throw new NotFoundHttpException('This side is closed to this profile.');
        }
        $family = $shown->family();
        $all = $documents->findOfTypes($dossier, $family);
        $counts = [];
        foreach ($family as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($all as $document) {
            ++$counts[$document->getType()->value];
        }
        $rows = array_values(array_filter($all, static fn (SalesDocument $d): bool => $d->getType() === $shown));
        $page = ListPage::fromRequest($request, 'sales.documents')->within(\count($rows));
        $shownRows = \array_slice($rows, $page->offset(), $page->perPage);
        $statuses = [];
        foreach ($shownRows as $document) {
            $statuses[(int) $document->getId()] = $receipts->status($document);
        }

        return $this->render('sales/documents.html.twig', [
            'dossier' => $dossier,
            'doc_type' => $shown,
            'family' => $family,
            'counts' => $counts,
            'document_rows' => $shownRows,
            'receipt_statuses' => $statuses,
            'shown_total' => \count($rows),
            'list_page' => $page,
        ]);
    }
}
