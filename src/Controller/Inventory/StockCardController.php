<?php

declare(strict_types=1);

namespace App\Controller\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\StockMovement;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Inventory\StockMovementRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Inventory\Stock\StockReader;
use App\Service\Shared\DateValue;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achats et stocks › Stocks › the fiche de stock of an article (step 2.5, gabarit R): its movements
 * to a day (the dossier's by default), each with what came in or went out - quantity, unit cost,
 * value at the article's method - and the stock after it; what each storage place holds. Read
 * under PURCHASES_READ; its address is the article's reference.
 */
#[Route('/d/{dossier}/inventory/stock/{code}', requirements: ['dossier' => '\d+', 'code' => '[A-Z0-9]{1,12}'])]
final class StockCardController extends AbstractController
{
    #[Route('', name: 'app_inventory_stock_card', methods: ['GET'])]
    public function show(Dossier $dossier, string $code, Request $request, ArticleRepository $articles, StockMovementRepository $movements, StockReader $reader): Response
    {
        if (!$this->isGranted(DossierRightVoter::PURCHASES_READ, $dossier)) {
            throw new NotFoundHttpException('Not open to this profile.');
        }
        $article = $articles->findOneByCode($dossier, $code);
        if (null === $article || !$article->isStockTracked()) {
            throw new NotFoundHttpException('No followed article of that reference.');
        }
        $at = DateValue::parse(QueryValue::trimmed($request, 'at')) ?? $dossier->getDossierDate();
        $byId = [];
        foreach ($movements->findOfArticle($article, $at) as $movement) {
            $byId[(int) $movement->getId()] = $movement;
        }

        return $this->render('inventory/stock/card.html.twig', [
            'dossier' => $dossier,
            'article' => $article,
            'at' => $at,
            'card' => $reader->card($article, $at),
            'movements_by_id' => $byId,
            'article_places' => $reader->places($article, $at),
            'main_location' => StockMovement::MAIN_LOCATION,
        ]);
    }
}
