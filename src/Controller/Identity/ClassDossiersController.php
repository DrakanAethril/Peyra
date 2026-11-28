<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Security\Voter\DossierVoter;
use App\Security\Voter\RestorePointVoter;
use App\Security\Voter\SchoolClassVoter;
use App\Service\Dossier\ClassDossierRow;
use App\Service\Dossier\ClassDossiers;
use App\Service\Dossier\DossierLive;
use App\Service\Dossier\Restore\RestoreBatches;
use App\Service\Dossier\Restore\RestorePointRules;
use App\Service\Dossier\Restore\RestoreQueue;
use App\Service\Dossier\Restore\RestoreRefused;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Classe › Dossiers (design-ux.md §§ 2.6, 3.3 and 4.9, gabarit L; lot 1, step 1.8d): the dossiers
 * of the class's students this year - holder, dossier, its date, its last restore point, its
 * « État de départ » -, read by every teacher of the field line by line as DossierVoter::VIEW lets
 * them. Once rows are ticked: « Poser un point » on each, and « Remettre à l'état de départ »
 * through a récapitulatif (gabarit E, one step). Each gesture on a dossier asks RestorePointVoter.
 */
#[Route('/teacher/classes/{id}/dossiers', requirements: ['id' => '\d+'])]
final class ClassDossiersController extends AbstractController
{
    #[Route('', name: 'app_teacher_class_dossiers', methods: ['GET'])]
    public function index(#[MapEntity] SchoolClass $class, ClassDossiers $dossiers, DossierRestorationRepository $restorations, RestoreQueue $queue, ClockInterface $clock): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::VIEW, $class);
        $rows = $this->visible($dossiers->rows($class));
        // The page asks again every two seconds while dossiers go back or points posed for the
        // class are prepared: each asking queues that work, so what the gesture's request left (its
        // WorkBudget spent) is done after it.
        foreach ($rows as $row) {
            $running = $row->restoring ? $restorations->findRunning($row->dossier) : null;
            if (null !== $running) {
                $queue->restoration($running);
            }
            if (null !== $row->lastPoint && $row->lastPoint->isPreparing()) {
                $queue->point($row->lastPoint);
            }
        }

        return $this->render('classes/dossiers.html.twig', [
            'class' => $class,
            'dossier_rows' => $rows,
            'tickable' => array_map(fn (ClassDossierRow $row): bool => $this->isGranted(RestorePointVoter::POSE, $row->dossier), $rows),
            'history' => !$this->isGranted(SchoolClassVoter::PLACE, $class),
            'point_name' => 'Point de la classe du '.$clock->now()->format('d/m/Y'),
            'busy' => array_any($rows, static fn (ClassDossierRow $row): bool => $row->restoring || (null !== $row->lastPoint && $row->lastPoint->isPreparing())),
        ]);
    }

    /** « Poser un point » on every ticked dossier, under one name. */
    #[Route('/points', name: 'app_teacher_class_dossiers_points', methods: ['POST'])]
    public function pose(#[MapEntity] SchoolClass $class, Request $request, ClassDossiers $dossiers, RestoreBatches $batches, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::VIEW, $class);
        $this->checkToken($request, 'class-dossiers-'.$class->getId());
        $rows = $this->ticked($dossiers->rows($class), PostValue::intList($request, 'dossiers'));
        if ([] === $rows) {
            $this->addFlash('status', 'Cochez les dossiers où poser un point.');
        } else {
            try {
                $outcome = $batches->pose($rows, PostValue::trimmed($request, 'name'), $me, $clock->now());
                $this->addFlash('status', $outcome->sentence('point posé : sa copie se prépare', 'points posés : leurs copies se préparent'));
            } catch (RestoreRefused $refused) {
                $this->addFlash('status', $refused->getMessage());
            }
        }

        return $this->redirectToRoute('app_teacher_class_dossiers', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    /** « Remettre à l'état de départ »: the récapitulatif of the ticked dossiers. */
    #[Route('/reset', name: 'app_teacher_class_dossiers_reset', methods: ['GET'])]
    public function summary(#[MapEntity] SchoolClass $class, Request $request, ClassDossiers $dossiers, RestoreBatches $batches): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::VIEW, $class);
        $rows = $this->ticked($dossiers->rows($class), QueryValue::intList($request, 'dossiers'));
        if ([] === $rows) {
            $this->addFlash('status', 'Cochez les dossiers à remettre à l’état de départ.');

            return $this->redirectToRoute('app_teacher_class_dossiers', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('classes/dossiers_reset.html.twig', [
            'class' => $class,
            'lines' => $batches->summaries($rows),
            'max_points' => RestorePointRules::LIMIT,
        ]);
    }

    #[Route('/reset', name: 'app_teacher_class_dossiers_reset_start', methods: ['POST'])]
    public function reset(#[MapEntity] SchoolClass $class, Request $request, ClassDossiers $dossiers, RestoreBatches $batches, DossierLive $live, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::VIEW, $class);
        $this->checkToken($request, 'class-dossiers-reset-'.$class->getId());
        $rows = $this->ticked($dossiers->rows($class), PostValue::intList($request, 'dossiers'));
        $outcome = $batches->resetToStart($rows, $request->request->getBoolean('keep'), $me, $clock->now());
        foreach ($rows as $row) {
            $live->announce($row->dossier, DossierLive::RESTORING);
        }
        $this->addFlash('status', $outcome->sentence('dossier remis à l’état de départ', 'dossiers remis à l’état de départ'));

        return $this->redirectToRoute('app_teacher_class_dossiers', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param list<ClassDossierRow> $rows
     *
     * @return list<ClassDossierRow> those the person may see
     */
    private function visible(array $rows): array
    {
        return array_values(array_filter($rows, fn (ClassDossierRow $row): bool => $this->isGranted(DossierVoter::VIEW, $row->dossier)));
    }

    /**
     * @param list<ClassDossierRow> $rows
     * @param list<int>             $ids
     *
     * @return list<ClassDossierRow> the ticked ones the person may pose points on and go back in
     */
    private function ticked(array $rows, array $ids): array
    {
        return array_values(array_filter($rows, fn (ClassDossierRow $row): bool => \in_array((int) $row->dossier->getId(), $ids, true)
            && $this->isGranted(RestorePointVoter::POSE, $row->dossier)
            && (null === $row->start || $this->isGranted(RestorePointVoter::RETURN, $row->start))));
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
