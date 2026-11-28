<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Accounting\FecImportJob;
use App\Enum\Accounting\FecImportState;
use App\Security\Voter\FecImportVoter;
use App\Service\Accounting\Fec\Import\FecImportQueue;
use App\Service\Dossier\Copy\DossierEraser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The progress of a FEC's import into a new template (step 1.10b): the stage reached and its bar,
 * redrawn live and asked again while passes are left - each asking runs a few more -; once done, what the template received and
 * the way to it; stopped, why, with « Relancer » (the next pass starts where the stopped one was
 * rolled back) and « Abandonner » (the hidden template deleted). Its author's (FecImportVoter).
 */
#[Route('/teacher/library/imports/{id}', requirements: ['id' => '\d+'])]
final class FecImportController extends AbstractController
{
    #[Route('', name: 'app_library_fec_import', methods: ['GET'])]
    public function show(#[MapEntity] FecImportJob $job, FecImportQueue $queue): Response
    {
        $this->guard($job);
        // The page asks again every two seconds while passes are left: each time, a few more run
        // after the answer (FecImportWorkListener), under the import's lock.
        if ($job->isPending()) {
            $queue->add($job);
        }

        return $this->render('library/fec_import.html.twig', ['job' => $job]);
    }

    #[Route('/retry', name: 'app_library_fec_import_retry', methods: ['POST'])]
    public function retry(#[MapEntity] FecImportJob $job, Request $request, EntityManagerInterface $entityManager, FecImportQueue $queue): Response
    {
        $this->guard($job);
        $this->token($job, $request);
        if (FecImportState::Failed === $job->getState()) {
            $job->retry();
            $entityManager->flush();
            $queue->add($job);
        }

        return $this->redirectToRoute('app_library_fec_import', ['id' => $job->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/abandon', name: 'app_library_fec_import_abandon', methods: ['POST'])]
    public function abandon(#[MapEntity] FecImportJob $job, Request $request, EntityManagerInterface $entityManager, DossierEraser $eraser): Response
    {
        $this->guard($job);
        $this->token($job, $request);
        if (FecImportState::Failed !== $job->getState()) {
            throw new NotFoundHttpException('Only a stopped import is abandoned.');
        }
        $template = $job->getTemplate();
        $entityManager->remove($job);
        $entityManager->flush();
        if (null !== $template) {
            $eraser->deleteDossier($template);
        }
        $this->addFlash('status', \sprintf('Import de %s abandonné : rien n’est gardé du fichier.', $job->getFileName()));

        return $this->redirectToRoute('app_library_new_fec', status: Response::HTTP_SEE_OTHER);
    }

    private function guard(FecImportJob $job): void
    {
        if (!$this->isGranted(FecImportVoter::MANAGE, $job)) {
            throw new NotFoundHttpException('Not an import of yours.');
        }
    }

    private function token(FecImportJob $job, Request $request): void
    {
        if (!$this->isCsrfTokenValid('fec-import-'.$job->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('A stale form.');
        }
    }
}
