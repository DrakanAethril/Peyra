<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Enum\Dossier\RestorationState;
use App\Repository\Dossier\DossierRestorationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The waiting page of a dossier going back to a restore point (design-ux.md § 4.9, step 3): every
 * tab open on the dossier comes here (RestorationInProgressListener, and the shell's live
 * subscription), and leaves once the return ends - to the dashboard when it is done, to the points'
 * list when it failed (the dossier then holds what it held). The page asks `…/state` every two
 * seconds besides listening to Mercure: the live update is a comfort.
 */
#[Route('/d/{dossier}/restoring', requirements: ['dossier' => '\d+'])]
final class RestoringController extends AbstractController
{
    /** How long after a return its sentence is still said to a tab that arrives. */
    private const int RECENT_SECONDS = 300;

    #[Route('', name: 'app_dossier_restoring', methods: ['GET'])]
    public function waiting(Dossier $dossier, DossierRestorationRepository $restorations, ClockInterface $clock): Response
    {
        $running = $restorations->findRunning($dossier);
        if (null !== $running) {
            return $this->render('dossier/restoring.html.twig', ['dossier' => $dossier, 'restoration' => $running]);
        }
        $latest = $restorations->findLatest($dossier);
        $finished = $latest?->getFinishedAt();
        $recent = null !== $finished && $clock->now()->getTimestamp() - $finished->getTimestamp() <= self::RECENT_SECONDS;
        if (null !== $latest && $recent && RestorationState::Failed === $latest->getState()) {
            return $this->redirectToRoute('app_dossier_restore_points', ['dossier' => $dossier->getId()]);
        }
        if (null !== $latest && $recent) {
            $this->addFlash('status', \sprintf('Le dossier est revenu au point « %s ».', $latest->getPointName()));
        }

        return $this->redirectToRoute('app_dossier_dashboard', ['dossier' => $dossier->getId()]);
    }

    #[Route('/state', name: 'app_dossier_restoring_state', methods: ['GET'])]
    public function state(Dossier $dossier, DossierRestorationRepository $restorations): JsonResponse
    {
        return new JsonResponse(['running' => null !== $restorations->findRunning($dossier)]);
    }
}
