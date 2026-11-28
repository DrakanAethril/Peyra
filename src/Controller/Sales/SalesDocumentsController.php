<?php

declare(strict_types=1);

namespace App\Controller\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesDocument;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Sales\SalesDocumentRepository;
use App\Security\Voter\DossierRightVoter;
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
 */
#[Route('/d/{dossier}/sales', requirements: ['dossier' => '\d+'])]
final class SalesDocumentsController extends AbstractController
{
    public const string TYPES = 'quote|order|delivery|invoice|credit_note|deposit';

    #[Route('/{type}', name: 'app_sales_documents', requirements: ['type' => self::TYPES], methods: ['GET'])]
    public function index(Dossier $dossier, string $type, Request $request, SalesDocumentRepository $documents): Response
    {
        if (!$this->isGranted(DossierRightVoter::SALES_READ, $dossier)) {
            throw new NotFoundHttpException('The sales are closed to this profile.');
        }
        $shown = SalesDocumentType::from($type);
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

        return $this->render('sales/documents.html.twig', [
            'dossier' => $dossier,
            'doc_type' => $shown,
            'family' => $family,
            'counts' => $counts,
            'document_rows' => \array_slice($rows, $page->offset(), $page->perPage),
            'shown_total' => \count($rows),
            'list_page' => $page,
        ]);
    }
}
