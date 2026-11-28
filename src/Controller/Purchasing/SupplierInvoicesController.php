<?php

declare(strict_types=1);

namespace App\Controller\Purchasing;

use App\Entity\Dossier\Dossier;
use App\Security\Voter\DossierRightVoter;
use App\Service\Purchasing\PurchaseMatching;
use App\Service\Shared\ListPage;
use App\Service\Shared\Money;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achats et stocks › Factures et avoirs reçus (step 2.4, gabarit L; design-ux.md § 3.2): the
 * suppliers' invoices and credit notes - pièces of the inbox, opened on their processing screen
 * (gabarit P), where the three-way match is among the controls - each with its match status; and
 * « Réceptions non facturées »: the validated orders whose receipts are worth more than what was
 * invoiced, valued at the order's prices - the invoices not yet arrived at the dossier's date.
 */
#[Route('/d/{dossier}/purchases/invoices', requirements: ['dossier' => '\d+'])]
final class SupplierInvoicesController extends AbstractController
{
    #[Route('', name: 'app_purchase_invoices', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, PurchaseMatching $matching): Response
    {
        if (!$this->isGranted(DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('The purchases are closed to this profile.');
        }
        $pieces = $matching->supplierPieces($dossier);
        $uninvoiced = $matching->uninvoiced($dossier);
        $view = 'uninvoiced' === QueryValue::trimmed($request, 'view') ? 'uninvoiced' : 'pieces';
        $page = ListPage::fromRequest($request, 'purchases.invoices')->within('pieces' === $view ? \count($pieces) : \count($uninvoiced));
        $shown = \array_slice($pieces, $page->offset(), $page->perPage);
        $statuses = [];
        foreach ($shown as $piece) {
            $statuses[(int) $piece->getId()] = $matching->status($piece, $pieces);
        }

        return $this->render('purchasing/invoices.html.twig', [
            'dossier' => $dossier,
            'view' => $view,
            'piece_rows' => $shown,
            'statuses' => $statuses,
            'pieces_total' => \count($pieces),
            'uninvoiced_rows' => \array_slice($uninvoiced, $page->offset(), $page->perPage),
            'uninvoiced_total' => \count($uninvoiced),
            'uninvoiced_sum' => Money::sum(array_map(static fn (array $row): Money => $row['toInvoice'], $uninvoiced)),
            'list_page' => $page,
        ]);
    }
}
