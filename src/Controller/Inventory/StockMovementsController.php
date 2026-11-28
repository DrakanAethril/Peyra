<?php

declare(strict_types=1);

namespace App\Controller\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\Article;
use App\Entity\Inventory\StockMovement;
use App\Enum\Inventory\StockMovementKind;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Inventory\StockMovementRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Inventory\Stock\StockMovementDraft;
use App\Service\Inventory\Stock\StockMovements;
use App\Service\Inventory\Stock\StockReader;
use App\Service\Inventory\Stock\StockShortage;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\DateValue;
use App\Service\Shared\ListPage;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Achats et stocks › Stocks › Mouvements (step 2.5, gabarit L): every movement of the dossier,
 * the latest first, filtered by kind and searched by article, each with the value it came in or
 * went out at; « Nouveau mouvement » (gabarit F) records one by hand - entry, exit, transfer,
 * adjustment - through StockMovements. Read under PURCHASES_READ, written under PURCHASES_ENTER.
 */
#[Route('/d/{dossier}/inventory/stock/movements', requirements: ['dossier' => '\d+'])]
final class StockMovementsController extends AbstractController
{
    #[Route('', name: 'app_inventory_stock_movements', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, StockMovementRepository $movements, StockReader $reader): Response
    {
        $this->denyUnless(DossierRightVoter::PURCHASES_READ, $dossier);
        $all = array_reverse($movements->findUntil($dossier));
        $kind = StockMovementKind::tryFrom(QueryValue::trimmed($request, 'kind'));
        $search = QueryValue::trimmed($request, 'q');
        $needle = mb_strtolower($search);
        $searched = '' === $search ? $all : array_values(array_filter($all, static fn (StockMovement $m): bool => str_contains(mb_strtolower($m->getArticle()->getCode().' '.$m->getArticle()->getDesignation()), $needle)));
        $counts = [];
        foreach ($searched as $movement) {
            $counts[$movement->getKind()->value] = ($counts[$movement->getKind()->value] ?? 0) + 1;
        }
        $shown = null === $kind ? $searched : array_values(array_filter($searched, static fn (StockMovement $m): bool => $m->getKind() === $kind));
        $page = ListPage::fromRequest($request, 'inventory.stock.movements')->within(\count($shown));

        return $this->render('inventory/stock/movements.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'movement_rows' => \array_slice($shown, $page->offset(), $page->perPage),
            'values' => [] === $all ? [] : $reader->values($dossier),
            'shown_total' => \count($shown),
            'searched_total' => \count($searched),
            'counts' => $counts,
            'kinds' => StockMovementKind::cases(),
            'kind' => $kind,
            'list_page' => $page,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_inventory_stock_movement_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, StockMovements $stock, StockReader $reader, ArticleRepository $articles, AssistanceLevels $levels, #[CurrentUser] User $me): Response
    {
        $this->denyUnless(DossierRightVoter::PURCHASES_ENTER, $dossier);
        $draft = new StockMovementDraft();
        $draft->date = $dossier->getDossierDate()->format('Y-m-d');
        $draft->kind = (StockMovementKind::tryFrom(QueryValue::trimmed($request, 'kind')) ?? StockMovementKind::Exit)->value;
        $draft->article = QueryValue::trimmed($request, 'article');
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('stock-movement-'.$dossier->getId(), PostValue::trimmed($request, '_token'))) {
                throw new NotFoundHttpException('Bad token.');
            }
            $draft->kind = PostValue::trimmed($request, 'kind');
            $draft->article = PostValue::trimmed($request, 'article');
            $draft->date = PostValue::trimmed($request, 'date');
            $draft->quantity = PostValue::trimmed($request, 'quantity');
            $draft->unitCost = PostValue::trimmed($request, 'unitCost');
            $draft->location = PostValue::trimmed($request, 'location');
            $draft->toLocation = PostValue::trimmed($request, 'toLocation');
            $draft->label = PostValue::trimmed($request, 'label');
            $fields = $stock->check($draft, $dossier);
            if (null !== $fields) {
                try {
                    $stock->record($dossier, $fields, $me);
                    $this->addFlash('status', \sprintf('Mouvement enregistré : %s de %s.', mb_strtolower($fields->kind->label()), $fields->article));

                    return $this->redirectToRoute('app_inventory_stock_card', ['dossier' => $dossier->getId(), 'code' => $fields->article], Response::HTTP_SEE_OTHER);
                } catch (StockShortage $shortage) {
                    $draft->errors['quantity'] = $shortage->getMessage();
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }
        $article = '' === $draft->article ? null : $articles->findOneByCode($dossier, strtoupper($draft->article));
        $article = null !== $article && $article->isStockTracked() ? $article : null;
        $at = $dossier->getDossierDate();

        return $this->render('inventory/stock/movement.html.twig', [
            'dossier' => $dossier,
            'draft' => $draft,
            'draft_date' => DateValue::parse($draft->date),
            'kinds' => StockMovementKind::cases(),
            'tracked' => array_values(array_filter($articles->findByDossier($dossier), static fn (Article $a): bool => $a->isStockTracked())),
            'places' => $reader->offeredPlaces($dossier),
            'article' => $article,
            'article_places' => null === $article ? [] : $reader->places($article, $at),
            'next_cost' => null !== $article && Assistance::at($levels->for($dossier))->showsStockValuation() ? $reader->card($article, $at)->nextExitCost : null,
        ], new Response(status: $status));
    }

    private function denyUnless(string $right, Dossier $dossier): void
    {
        if (!$this->isGranted($right, $dossier)) {
            throw new NotFoundHttpException('Not open to this profile.');
        }
    }
}
