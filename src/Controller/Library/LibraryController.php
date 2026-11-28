<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use App\Enum\Dossier\TemplateShelf;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Dossier\DossierShareRepository;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bibliothèque › Dossiers modèles (design-ux.md §§ 2.6 and 3.3, gabarit L en cartes): the teacher's
 * templates, those shared with them, the common library - filtered by shelf and searched - and
 * Bibliothèque › Partages, the shares they gave and received.
 */
final class LibraryController extends AbstractController
{
    #[Route('/teacher/library', name: 'app_library', methods: ['GET'])]
    public function index(Request $request, TemplateLibrary $library, #[CurrentUser] User $me): Response
    {
        $shelf = TemplateShelf::tryFrom(QueryValue::trimmed($request, 'shelf'));
        $search = QueryValue::trimmed($request, 'q');
        $cards = $library->cards($me);
        $counts = [];
        foreach (TemplateShelf::cases() as $case) {
            $counts[$case->value] = \count(array_filter($cards, static fn (TemplateCard $card): bool => $card->shelf === $case));
        }
        $shown = array_values(array_filter($cards, static fn (TemplateCard $card): bool => (null === $shelf || $card->shelf === $shelf) && $card->matches($search)));

        return $this->render('library/index.html.twig', [
            'cards' => $shown,
            'total' => \count($cards),
            'counts' => $counts,
            'shelf' => $shelf,
            'shelves' => TemplateShelf::cases(),
            'search' => $search,
        ]);
    }

    #[Route('/teacher/library/shares', name: 'app_library_shares', methods: ['GET'])]
    public function shares(DossierRepository $dossiers, DossierShareRepository $shares, #[CurrentUser] User $me): Response
    {
        $given = [];
        foreach ($dossiers->findTemplatesOf($me) as $template) {
            foreach ($shares->findByDossier($template) as $share) {
                $given[] = $share;
            }
        }
        $received = array_values(array_filter($shares->findForTeacher($me), static fn (DossierShare $share): bool => $share->getDossier()->isTemplate() && $share->getDossier()->getOwner() !== $me));

        return $this->render('library/shares.html.twig', ['given' => $given, 'received' => $received]);
    }
}
