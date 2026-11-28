<?php

declare(strict_types=1);

namespace App\Controller\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Security\Voter\DossierVoter;
use App\Service\Mission\MissionRefused;
use App\Service\Mission\Play\StudentMissions;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The student's gestures from the mission panel (step 2.9b; design-ux.md § 2.3): « Tâche terminée » /
 * « Rouvrir la tâche », and « Rendre la mission » (StudentMissions). A student who works in the
 * dossier only; anybody else reads a 404. Each answer goes back to the screen the panel was opened
 * on (`return`, a path of the same dossier), the status bar saying what was done.
 */
#[Route('/d/{dossier}/mission', requirements: ['dossier' => '\d+'])]
final class MissionPlayController extends AbstractController
{
    #[Route('/tasks/{key}', name: 'app_mission_play_task', requirements: ['key' => 't[0-9a-f]{1,8}'], methods: ['POST'])]
    public function task(Dossier $dossier, string $key, Request $request, StudentMissions $missions, #[CurrentUser] User $me): Response
    {
        $this->refuse($dossier, $request, $me);
        $done = $request->request->getBoolean('done');
        try {
            $missions->declare($dossier, $key, $done, $me);
            $this->addFlash('status', $done ? 'Tâche terminée.' : 'Tâche rouverte.');
        } catch (MissionRefused $refused) {
            $this->addFlash('status', $refused->getMessage());
        }

        return $this->back($dossier, $request);
    }

    #[Route('/submit', name: 'app_mission_play_submit', methods: ['POST'])]
    public function submit(Dossier $dossier, Request $request, StudentMissions $missions, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->refuse($dossier, $request, $me);
        try {
            $missions->submit($dossier, $me, $clock->now());
            $this->addFlash('status', 'Mission rendue : le point « Rendu » fige votre dossier tel qu’il est, votre enseignant le corrigera.');
        } catch (MissionRefused $refused) {
            $this->addFlash('status', $refused->getMessage());
        }

        return $this->back($dossier, $request);
    }

    private function refuse(Dossier $dossier, Request $request, User $me): void
    {
        if (!$me->isStudent() || !$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('mission-play-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not a student of this dossier.');
        }
    }

    private function back(Dossier $dossier, Request $request): RedirectResponse
    {
        $id = (int) $dossier->getId();

        return $this->redirect(ReturnPath::of($request->request->getString('return'), $id) ?? $this->generateUrl('app_dossier_dashboard', ['dossier' => $id]), Response::HTTP_SEE_OTHER);
    }
}
