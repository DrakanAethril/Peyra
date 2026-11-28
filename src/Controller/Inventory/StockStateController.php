<?php

declare(strict_types=1);

namespace App\Controller\Inventory;

use App\Entity\Dossier\Dossier;
use App\Security\Voter\DossierRightVoter;
use App\Service\Inventory\Stock\StockLine;
use App\Service\Inventory\Stock\StockReader;
use App\Service\Shared\Csv\CsvText;
use App\Service\Shared\DateValue;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Achats et stocks › Stocks › État du stock (step 2.5, gabarit R): every followed article at a day
 * (the dossier's by default) - quantity, unit cost, value at its method, its minimum -, those
 * below their minimum signalled (« rupture ») and filtered apart, the total value; « Exporter »
 * writes it as CSV. Read under PURCHASES_READ.
 */
#[Route('/d/{dossier}/inventory/stock', requirements: ['dossier' => '\d+'])]
final class StockStateController extends AbstractController
{
    #[Route('', name: 'app_inventory_stock_state', methods: ['GET'])]
    public function show(Dossier $dossier, Request $request, StockReader $reader): Response
    {
        $at = $this->open($dossier, $request);
        $lines = $reader->state($dossier, $at);
        $below = array_values(array_filter($lines, static fn (StockLine $line): bool => $line->isBelowMinimum()));
        $alerts = 'below' === QueryValue::trimmed($request, 'view');

        return $this->render('inventory/stock/state.html.twig', [
            'dossier' => $dossier,
            'at' => $at,
            'stock_lines' => $alerts ? $below : $lines,
            'line_total' => \count($lines),
            'below_total' => \count($below),
            'alerts' => $alerts,
            'total_value' => Money::sum(array_map(static fn (StockLine $line): Money => $line->position->value, $alerts ? $below : $lines)),
        ]);
    }

    #[Route('/export.csv', name: 'app_inventory_stock_state_export', methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, StockReader $reader): Response
    {
        $at = $this->open($dossier, $request);
        $rows = [['reference', 'designation', 'unite', 'methode', 'quantite', 'cout_unitaire', 'valeur', 'stock_minimum', 'sous_le_minimum']];
        foreach ($reader->state($dossier, $at) as $line) {
            $article = $line->article;
            $cost = $line->position->unitCost();
            $minimum = $article->getMinimumStock();
            $rows[] = [
                $article->getCode(),
                $article->getDesignation(),
                $article->getUnit(),
                $article->getValuationMethod()->shortLabel(),
                str_replace('.', ',', (string) $line->position->quantity->strippedOfTrailingZeros()),
                null === $cost ? '' : FrenchFormat::amount($cost),
                FrenchFormat::amount($line->position->value),
                null === $minimum ? '' : str_replace('.', ',', (string) $minimum->strippedOfTrailingZeros()),
                $line->isBelowMinimum() ? 'oui' : 'non',
            ];
        }
        $response = new Response(CsvText::write($rows));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $name = 'etat-du-stock-'.(new AsciiSlugger('fr'))->slug($dossier->getName())->lower().'-'.$at->format('Y-m-d').'.csv';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name));

        return $response;
    }

    /** The day read: `at` when it is one, else the dossier's date. */
    private function open(Dossier $dossier, Request $request): \DateTimeImmutable
    {
        if (!$this->isGranted(DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('Not open to this profile.');
        }

        return DateValue::parse(QueryValue::trimmed($request, 'at')) ?? $dossier->getDossierDate();
    }
}
