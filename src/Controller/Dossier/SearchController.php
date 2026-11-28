<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Dossier\Search\GlobalSearch;
use App\Service\Mission\CurrentMission;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The global search's results (Ctrl K, design-ux.md § 2.1): a GET the palette sends 150 ms after
 * the last key, answered as the Turbo Frame `px-cmdk-results`. `q` is what is typed, `here` the
 * screen the search is made from (the way back of what it opens). Never outside the dossier,
 * which DossierRouteListener has opened.
 */
final class SearchController extends AbstractController
{
    public function __construct(private readonly CurrentMission $missions)
    {
    }

    #[Route('/d/{dossier}/search', name: 'app_dossier_search', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function search(Dossier $dossier, Request $request, GlobalSearch $search, AssistanceLevels $levels): Response
    {
        $canEdit = $this->isGranted(DossierVoter::EDIT, $dossier);
        $query = mb_substr(QueryValue::trimmed($request, 'q'), 0, 100);

        return $this->render('dossier/_search_results.html.twig', [
            'query' => $query,
            'sections' => $search->search(
                $dossier,
                $query,
                $canEdit,
                Assistance::at($levels->for($dossier))->opensGuidedEntry($this->isGranted('ROLE_TEACHER'), $this->missions->opensGuidedEntry($dossier)),
                ReturnPath::of(QueryValue::trimmed($request, 'here'), (int) $dossier->getId()),
            ),
        ]);
    }
}
