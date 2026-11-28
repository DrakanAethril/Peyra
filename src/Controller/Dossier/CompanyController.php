<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Dossier\ExerciseRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Dossier\CompanyDraft;
use App\Service\Dossier\CompanySheet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Système d'information › Société (design-ux.md § 3.2, gabarit F): the company's identity, its
 * regimes, and its exercises with their periods (read: they open and close with the closing
 * screens). F10 saves for whoever works in the dossier and keeps its settings
 * (DossierRightVoter::SETTINGS: in a group's dossier, the profile decides); anybody else reads.
 */
final class CompanyController extends AbstractController
{
    #[Route('/d/{dossier}/company', name: 'app_dossier_company', requirements: ['dossier' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Dossier $dossier, Request $request, CompanySheet $sheet, ExerciseRepository $exercises, PeriodRepository $periods, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $draft = CompanyDraft::of($dossier->getCompany());
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            if (!$this->isGranted(DossierRightVoter::SETTINGS, $dossier) || !$this->isCsrfTokenValid('dossier-company-'.$dossier->getId(), $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Not yours to change.');
            }
            $draft = CompanyDraft::fromRequest($request);
            $company = $sheet->check($draft);
            if (null !== $company) {
                $changed = $sheet->save($dossier, $company, $me);
                $entityManager->flush();
                $this->addFlash('status', $changed ? 'Fiche société enregistrée.' : 'Rien à enregistrer : la fiche est inchangée.');

                return $this->redirectToRoute('app_dossier_company', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $byExercise = [];
        foreach ($periods->findByDossier($dossier) as $period) {
            $byExercise[(int) $period->getExercise()->getId()][] = $period;
        }

        return $this->render('dossier/company.html.twig', [
            'dossier' => $dossier,
            'draft' => $draft,
            'exercises' => $exercises->findByDossier($dossier),
            'periods' => $byExercise,
        ], new Response(status: $status));
    }
}
