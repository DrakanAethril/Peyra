<?php

declare(strict_types=1);

namespace App\Controller\Assets;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Assets\Dotation\DotationLine;
use App\Service\Assets\FixedAssetPostings;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Immobilisations et clôture › Dotations (step 3.4b, gabarit E; design-ux.md § 3.2 « Dotations »): the
 * dotations of the period - from the last day they were passed for each asset to the dossier's date -,
 * asset by asset, with the dérogatoire and, on the exercise's last day, the impairment's movement; the
 * entry in preview and « Comptabiliser les dotations » through the gateway (FixedAssetPostings). Read
 * with ASSETS_READ, posted with ASSETS_ENTER; at « Aucun filet » a student computes them alone.
 */
#[Route('/d/{dossier}/assets/dotations', requirements: ['dossier' => '\d+'])]
final class FixedAssetDotationsController extends AbstractController
{
    #[Route('', name: 'app_assets_dotations', methods: ['GET'])]
    public function index(Dossier $dossier, FixedAssetPostings $postings, AssistanceLevels $levels): Response
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_READ, $dossier)) {
            throw new NotFoundHttpException('Not for this profile.');
        }
        $exercise = $postings->dotationExercise($dossier);
        $calculates = Assistance::at($levels->for($dossier))->calculatesDepreciationPlan($this->isGranted('ROLE_TEACHER'));
        $through = null === $exercise ? null : min($dossier->getDossierDate(), $exercise->getEndsOn());
        $lines = null === $exercise || null === $through || !$calculates ? [] : $postings->dotationLines($dossier, $exercise, $through);
        $proposal = null === $through ? null : $postings->dotationProposal($dossier, $lines, $through);
        $document = null === $through ? '' : FixedAssetPostings::dotationDocument($through);

        return $this->render('assets/dotations.html.twig', [
            'dossier' => $dossier,
            'exercise' => $exercise,
            'through' => $through,
            'calculates' => $calculates,
            'lines' => $lines,
            'total' => Money::sum(array_map(static fn (DotationLine $line): Money => $line->dotation, $lines)),
            'behaviour' => $postings->behaviour($dossier),
            'document' => $document,
            'entry' => [
                'entries' => $postings->entries($dossier, $document),
                'standing' => $postings->standing($dossier, $document),
                'proposal' => $proposal,
                'check' => null === $proposal ? null : $postings->check($dossier, $proposal),
            ],
        ]);
    }

    #[Route('/post', name: 'app_assets_dotations_post', methods: ['POST'])]
    public function post(Dossier $dossier, Request $request, FixedAssetPostings $postings, AssistanceLevels $levels, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierRightVoter::ASSETS_ENTER, $dossier) || !$this->isCsrfTokenValid('fixed-asset-dotations-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to change.');
        }
        $exercise = $postings->dotationExercise($dossier);
        if (null === $exercise || AccountingBehaviour::LetEnter === $postings->behaviour($dossier) || !Assistance::at($levels->for($dossier))->calculatesDepreciationPlan($this->isGranted('ROLE_TEACHER'))) {
            throw new NotFoundHttpException('No dotations are posted from here.');
        }
        $through = min($dossier->getDossierDate(), $exercise->getEndsOn());
        if (null === $postings->dotationProposal($dossier, $postings->dotationLines($dossier, $exercise, $through), $through)) {
            $this->addFlash('status', 'Aucune dotation à passer : tout est passé jusqu’à la date du dossier.');

            return $this->redirectToRoute('app_assets_dotations', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
        }
        $check = $postings->postDotations($dossier, $me);
        if ($check->posted) {
            $entityManager->flush();
            $this->addFlash('status', \sprintf('Dotations au %s passées au brouillard.', $through->format('d/m/Y')));
        } else {
            $this->addFlash('error', 'Les dotations ne sont pas passées : '.implode(' ', array_unique([...array_values($check->errors), ...array_map(static fn ($control): string => $control->message, array_filter($check->controls(), static fn ($control): bool => $control->blocking))])));
        }

        return $this->redirectToRoute('app_assets_dotations', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }
}
