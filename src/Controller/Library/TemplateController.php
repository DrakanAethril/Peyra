<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Dossier\TemplateLevel;
use App\Enum\Shared\BtsProcess;
use App\Repository\Dossier\DossierShareRepository;
use App\Security\Voter\DossierVoter;
use App\Security\Voter\TemplateVoter;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateCardDraft;
use App\Service\Dossier\Library\TemplateCards;
use App\Service\Dossier\Library\TemplateContents;
use App\Service\Dossier\Library\TemplateDeletion;
use App\Service\Dossier\Library\TemplateDuplicator;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Shared\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A template's fiche in the library (design-ux.md § 3.3, gabarit F): its card - title,
 * description, level, processes, F10 for whoever may change it -, what it holds, its shares
 * (TemplateSharesController), « Ouvrir le dossier », « Dupliquer », « Supprimer » (a récapitulatif,
 * then its title typed, design-ux.md § 6.2). A dossier that is not a template answers 404 here.
 */
final class TemplateController extends AbstractController
{
    #[Route('/teacher/library/{dossier}', name: 'app_library_template', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Dossier $dossier, Request $request, TemplateLibrary $library, TemplateCards $cards, TemplateContents $contents, DossierShareRepository $shares, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $card = $this->card($library, $me, $dossier);
        $draft = TemplateCardDraft::of($dossier);
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('library-card-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $draft = TemplateCardDraft::fromRequest($request);
            if ($cards->check($draft)) {
                $changed = $cards->save($dossier, $draft, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? 'Fiche du modèle enregistrée.' : 'Rien à enregistrer : la fiche est inchangée.');

                return $this->redirectToRoute('app_library_template', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('library/template.html.twig', [
            'dossier' => $dossier,
            'card' => $card,
            'draft' => $draft,
            'contents' => $contents->of($dossier),
            'shares' => $this->isGranted(TemplateVoter::SHARE, $dossier) ? $shares->findByDossier($dossier) : [],
            'share_error' => null,
            'share_label' => '',
            'levels' => self::levels(),
            'processes' => BtsProcess::cases(),
        ], new Response(status: $status));
    }

    #[Route('/teacher/library/{dossier}/duplicate', name: 'app_library_duplicate', requirements: ['dossier' => '\d+'], methods: ['POST'])]
    public function duplicate(Dossier $dossier, Request $request, TemplateDuplicator $duplicator, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$dossier->isTemplate() || !$this->isGranted(TemplateVoter::DUPLICATE, $dossier) || !$this->isCsrfTokenValid('library-duplicate-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not a template you read.');
        }
        $copy = $duplicator->duplicate($dossier, $me, $clock->now());
        $this->addFlash('status', \sprintf('Modèle « %s » créé, copie de « %s ».', $copy->getLibraryTitle(), $dossier->getLibraryTitle()));

        return $this->redirectToRoute('app_library_template', ['dossier' => $copy->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/teacher/library/{dossier}/delete', name: 'app_library_delete', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function delete(Dossier $dossier, Request $request, TemplateLibrary $library, TemplateContents $contents, TemplateDeletion $deletion, DossierShareRepository $shares, #[CurrentUser] User $me): Response
    {
        $card = $this->card($library, $me, $dossier);
        if (!$this->isGranted(TemplateVoter::DELETE, $dossier)) {
            throw new NotFoundHttpException('Not yours to delete.');
        }
        $error = null;
        $typed = '';
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('library-delete-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('A stale form.');
            }
            $typed = PostValue::trimmed($request, 'confirmTitle');
            $title = $dossier->getLibraryTitle();
            $error = $deletion->delete($dossier, $typed, $me);
            if (null === $error) {
                $this->addFlash('status', \sprintf('Modèle « %s » supprimé.', $title));

                return $this->redirectToRoute('app_library', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('library/delete.html.twig', [
            'dossier' => $dossier,
            'card' => $card,
            'contents' => $contents->of($dossier),
            'shares' => \count($shares->findByDossier($dossier)),
            'error' => $error,
            'typed' => $typed,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** @return list<array{value: string, label: string}> */
    public static function levels(): array
    {
        return array_map(static fn (TemplateLevel $level): array => ['value' => $level->value, 'label' => $level->label()], TemplateLevel::cases());
    }

    private function card(TemplateLibrary $library, User $me, Dossier $dossier): TemplateCard
    {
        $card = $dossier->isTemplate() ? $library->card($me, $dossier) : null;

        return $card ?? throw new NotFoundHttpException('Not a template of the library.');
    }
}
