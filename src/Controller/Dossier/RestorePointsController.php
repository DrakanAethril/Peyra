<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Security\Voter\RestorePointVoter;
use App\Service\Dossier\DossierLive;
use App\Service\Dossier\Restore\Restorations;
use App\Service\Dossier\Restore\RestorePointRules;
use App\Service\Dossier\Restore\RestorePoints;
use App\Service\Dossier\Restore\RestoreQueue;
use App\Service\Dossier\Restore\RestoreRefused;
use App\Service\Dossier\Restore\RestoreSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Système d'information › Points de restauration (design-ux.md §§ 3.2 and 4.9, gabarit L; lot 1,
 * step 1.8b): the dossier's points, newest first - name, real time, the dossier's date, author,
 * state -, « Poser un point » (a name, Entrée: the copy follows in the background), « Revenir à
 * ce point » through a récapitulatif, and deletion in two steps. Rights: RestorePointVoter; the
 * gestures go through RestorePoints and Restorations.
 */
#[Route('/d/{dossier}/restore-points', requirements: ['dossier' => '\d+'])]
final class RestorePointsController extends AbstractController
{
    public function __construct(
        private readonly RestorePointRepository $points,
        private readonly DossierRestorationRepository $restorations,
        private readonly RestoreQueue $queue,
    ) {
    }

    #[Route('', name: 'app_dossier_restore_points', methods: ['GET'])]
    public function index(Dossier $dossier): Response
    {
        return $this->list($dossier);
    }

    /** The same list, its name field taken (the switcher's and Ctrl K's « Poser un point de restauration »). */
    #[Route('/new', name: 'app_dossier_restore_point_new', methods: ['GET'])]
    public function new(Dossier $dossier): Response
    {
        return $this->list($dossier, focus: true);
    }

    #[Route('', name: 'app_dossier_restore_point_pose', methods: ['POST'])]
    public function pose(Dossier $dossier, Request $request, RestorePoints $restorePoints, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(RestorePointVoter::POSE, $dossier) || !$this->isCsrfTokenValid('restore-points-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to pose.');
        }
        $name = $request->request->getString('name');
        try {
            $point = $restorePoints->pose($dossier, $name, $me, $clock->now());
        } catch (RestoreRefused $refused) {
            return $this->list($dossier, focus: true, name: $name, nameError: $refused->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('status', \sprintf('Point « %s » posé : sa copie se prépare, le travail continue.', $point->getName()));

        return $this->redirectToRoute('app_dossier_restore_points', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{point}/delete', name: 'app_dossier_restore_point_delete', requirements: ['point' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, int $point, Request $request, RestorePoints $restorePoints, #[CurrentUser] User $me): Response
    {
        $restorePoint = $this->pointOf($dossier, $point, RestorePointVoter::DELETE);
        if (!$this->isCsrfTokenValid('restore-point-delete-'.$point, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to delete.');
        }
        $name = $restorePoint->getName();
        try {
            $restorePoints->delete($restorePoint, $me);
        } catch (RestoreRefused $refused) {
            return $this->list($dossier, error: $refused->getMessage(), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('status', \sprintf('Point « %s » supprimé.', $name));

        return $this->redirectToRoute('app_dossier_restore_points', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    /** « Revenir à ce point »: the récapitulatif (gabarit E, one step), then the return. */
    #[Route('/{point}/return', name: 'app_dossier_restore_point_return', requirements: ['point' => '\d+'], methods: ['GET'])]
    public function summary(Dossier $dossier, int $point, RestoreSummary $summary): Response
    {
        $restorePoint = $this->pointOf($dossier, $point, RestorePointVoter::RETURN);
        if (!$restorePoint->isReady()) {
            throw new NotFoundHttpException('Only a ready point is gone back to.');
        }

        return $this->renderSummary($dossier, $restorePoint, $summary);
    }

    #[Route('/{point}/return', name: 'app_dossier_restore_point_return_start', requirements: ['point' => '\d+'], methods: ['POST'])]
    public function start(Dossier $dossier, int $point, Request $request, Restorations $restorations, RestoreSummary $summary, DossierLive $live, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $restorePoint = $this->pointOf($dossier, $point, RestorePointVoter::RETURN);
        if (!$this->isCsrfTokenValid('restore-point-return-'.$point, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to go back to.');
        }
        $keep = $request->request->getBoolean('keep');
        try {
            $restorations->returnTo($restorePoint, $keep, $me, $clock->now());
        } catch (RestoreRefused $refused) {
            return $this->renderSummary($dossier, $restorePoint, $summary, $refused->getMessage(), $keep, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $live->announce($dossier, DossierLive::RESTORING);

        return $this->redirectToRoute('app_dossier_restoring', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    private function pointOf(Dossier $dossier, int $id, string $attribute): RestorePoint
    {
        $point = $this->points->find($id);
        if (null === $point || $point->getDossier() !== $dossier || !$this->isGranted($attribute, $point)) {
            throw new NotFoundHttpException('No such point in this dossier, or not yours.');
        }

        return $point;
    }

    private function list(Dossier $dossier, bool $focus = false, ?string $name = null, ?string $nameError = null, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $points = $this->points->findByDossier($dossier);
        $latest = $this->restorations->findLatest($dossier);
        // The list asks again while a copy is under way: each asking makes what a request left
        // (its WorkBudget ran out, a class given a point at once) after the answer.
        foreach ($points as $point) {
            if ($point->isPreparing()) {
                $this->queue->point($point);
            }
        }

        return $this->render('dossier/restore_points.html.twig', [
            'dossier' => $dossier,
            'points' => $points,
            'can_pose' => $this->isGranted(RestorePointVoter::POSE, $dossier),
            'full' => RestorePointRules::limitReached(\count($points)),
            'limit' => RestorePointRules::LIMIT,
            'focus' => $focus,
            'name' => $name ?? RestorePointRules::defaultName($dossier->getDossierDate()),
            'name_error' => $nameError,
            'error' => $error,
            'failed_return' => null !== $latest && null !== $latest->getFailure() ? $latest : null,
            'preparing' => array_any($points, static fn (RestorePoint $point): bool => $point->isPreparing()),
        ], new Response(status: $status));
    }

    private function renderSummary(Dossier $dossier, RestorePoint $point, RestoreSummary $summary, ?string $error = null, bool $keep = true, int $status = Response::HTTP_OK): Response
    {
        $full = RestorePointRules::limitReached($this->points->countAgainstLimit($dossier));

        return $this->render('dossier/restore_point_return.html.twig', [
            'dossier' => $dossier,
            'point' => $point,
            'summary' => $summary->of($point),
            'full' => $full,
            'keep' => $keep && !$full,
            'error' => $error,
        ], new Response(status: $status));
    }
}
