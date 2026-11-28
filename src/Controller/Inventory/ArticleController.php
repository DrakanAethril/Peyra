<?php

declare(strict_types=1);

namespace App\Controller\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\Article;
use App\Repository\Inventory\ArticleRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Inventory\ArticleDraft;
use App\Service\Inventory\ArticleSheet;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The fiche of an article (step 2.3, gabarit F): reference, designation, unit, selling price, VAT
 * rate, revenue account (the account picker, class 7), stock followed; F10 saves under
 * DOSSIER_SETTINGS, « Supprimer » in two steps. Its address is its reference.
 */
#[Route('/d/{dossier}/inventory/articles', requirements: ['dossier' => '\d+'])]
final class ArticleController extends AbstractController
{
    private const string CODE = '[A-Z0-9]{1,12}';

    #[Route('/new', name: 'app_inventory_article_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, ArticleSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->settings($dossier);
        $draft = new ArticleDraft();
        $draft->unit = 'pièce';
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->read($request, $draft, 'article-new-'.$dossier->getId());
            $fields = $sheet->check($draft, $dossier);
            if (null !== $fields) {
                $sheet->create($dossier, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Article %s créé.', $fields->code));

                return $this->redirectToRoute('app_inventory_article', ['dossier' => $dossier->getId(), 'code' => $fields->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('inventory/article.html.twig', ['dossier' => $dossier, 'article' => null, 'draft' => $draft], new Response(status: $status));
    }

    #[Route('/{code}', name: 'app_inventory_article', requirements: ['code' => self::CODE], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, string $code, Request $request, ArticleRepository $articles, ArticleSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $article = $this->article($dossier, $code, $articles);
        $draft = ArticleDraft::of($article);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->settings($dossier);
            $this->read($request, $draft, 'article-'.$article->getId());
            $fields = $sheet->check($draft, $dossier, $article);
            if (null !== $fields) {
                $sheet->update($article, $fields, $me);
                $entityManager->flush();
                $this->addFlash('status', \sprintf('Article %s enregistré.', $fields->code));

                return $this->redirectToRoute('app_inventory_article', ['dossier' => $dossier->getId(), 'code' => $fields->code], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('inventory/article.html.twig', ['dossier' => $dossier, 'article' => $article, 'draft' => $draft], new Response(status: $status));
    }

    #[Route('/{code}/delete', name: 'app_inventory_article_delete', requirements: ['code' => self::CODE], methods: ['POST'])]
    public function delete(Dossier $dossier, string $code, Request $request, ArticleRepository $articles, ArticleSheet $sheet, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $article = $this->article($dossier, $code, $articles);
        $this->settings($dossier);
        if (!$this->isCsrfTokenValid('article-delete-'.$article->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
        $sheet->delete($article, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Article %s supprimé.', $code));

        return $this->redirectToRoute('app_inventory_articles', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function settings(Dossier $dossier): void
    {
        if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier)) {
            throw new NotFoundHttpException('Not yours to change.');
        }
    }

    private function article(Dossier $dossier, string $code, ArticleRepository $articles): Article
    {
        return $articles->findOneByCode($dossier, $code) ?? throw new NotFoundHttpException('No such article.');
    }

    private function read(Request $request, ArticleDraft $draft, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, PostValue::trimmed($request, '_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }
        $draft->code = PostValue::trimmed($request, 'code');
        $draft->designation = PostValue::trimmed($request, 'designation');
        $draft->unit = PostValue::trimmed($request, 'unit');
        $draft->salePrice = PostValue::trimmed($request, 'salePrice');
        $draft->vatRate = PostValue::trimmed($request, 'vatRate');
        $draft->revenueAccount = PostValue::trimmed($request, 'revenueAccount');
        $draft->stockTracked = '1' === PostValue::trimmed($request, 'stockTracked');
    }
}
