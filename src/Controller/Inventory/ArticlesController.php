<?php

declare(strict_types=1);

namespace App\Controller\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\Article;
use App\Repository\Inventory\ArticleRepository;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achats et stocks › Articles (step 2.3, gabarit L): the dossier's articles by reference, searched
 * by reference or designation. An article opens its fiche (ArticleController). Read by whoever
 * enters the dossier; written under DOSSIER_SETTINGS, like the third parties.
 */
#[Route('/d/{dossier}/inventory/articles', requirements: ['dossier' => '\d+'])]
final class ArticlesController extends AbstractController
{
    #[Route('', name: 'app_inventory_articles', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, ArticleRepository $articles): Response
    {
        $all = $articles->findByDossier($dossier);
        $search = QueryValue::trimmed($request, 'q');
        $needle = mb_strtolower($search);
        $found = '' === $search ? $all : array_values(array_filter($all, static fn (Article $a): bool => str_contains(mb_strtolower($a->getCode().' '.$a->getDesignation()), $needle)));
        $page = ListPage::fromRequest($request, 'inventory.articles')->within(\count($found));

        return $this->render('inventory/articles.html.twig', [
            'dossier' => $dossier,
            'total' => \count($all),
            'article_rows' => \array_slice($found, $page->offset(), $page->perPage),
            'shown_total' => \count($found),
            'list_page' => $page,
            'search' => $search,
        ]);
    }
}
