<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Repository\Dossier\ExerciseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The dossier's dashboard (design-ux.md § 3.2, gabarit T), where the switcher and the rail's brand
 * lead. Empty until the modules feed it: the company, its exercise and the dossier's date, and what
 * the indicators will show. DossierRouteListener has opened the dossier.
 */
final class DashboardController extends AbstractController
{
    #[Route('/d/{dossier}', name: 'app_dossier_dashboard', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function index(Dossier $dossier, ExerciseRepository $exercises): Response
    {
        return $this->render('dossier/dashboard.html.twig', [
            'dossier' => $dossier,
            'exercises' => $exercises->findByDossier($dossier),
        ]);
    }
}
