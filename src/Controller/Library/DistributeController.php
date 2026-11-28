<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierProfile;
use App\Security\Voter\TemplateVoter;
use App\Service\Dossier\Distribution\DistributionChoices;
use App\Service\Dossier\Distribution\DistributionPlan;
use App\Service\Dossier\Distribution\DistributionPlanner;
use App\Service\Dossier\Distribution\DistributionRefused;
use App\Service\Dossier\Distribution\Distributions;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Distribuer » a template (design-ux.md § 4.2, gabarit E): 1. À qui - a class of the current
 * year, its students ticked but those who already hold a copy of the template; 2. Comment - a
 * dossier each, or one shared by each group with a profile per member, and the date the dossiers
 * start at; 3. the récapitulatif, then « Distribuer » (Ctrl Entrée). Steps 1 to 3 are GET pages
 * carrying the choices (DistributionChoices); the gesture is the POST of step 3, which sends to
 * the distribution's progress page while its dossiers are made in the background.
 */
#[Route('/teacher/library/{dossier}/distribute', requirements: ['dossier' => '\d+'])]
final class DistributeController extends AbstractController
{
    #[Route('', name: 'app_library_distribute', methods: ['GET'])]
    public function who(Dossier $dossier, Request $request, DistributionPlanner $planner, #[CurrentUser] User $me): Response
    {
        $this->guard($dossier);
        $class = $this->schoolClass($request, $planner, $me);

        return $this->render('library/distribute_who.html.twig', [
            'template' => $dossier,
            'classes' => $planner->classes(),
            'school_class' => $class,
            'plan' => null === $class ? null : $planner->plan($dossier, $class, DistributionChoices::fromQuery($request)),
        ]);
    }

    #[Route('/how', name: 'app_library_distribute_how', methods: ['GET'])]
    public function how(Dossier $dossier, Request $request, DistributionPlanner $planner, #[CurrentUser] User $me): Response
    {
        $this->guard($dossier);

        return $this->render('library/distribute_how.html.twig', [
            'template' => $dossier,
            'plan' => $this->plan($dossier, $request, $planner, $me, DistributionChoices::fromQuery($request)),
            'modes' => array_map(static fn (DistributionMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()], DistributionMode::cases()),
            'profiles' => array_map(static fn (DossierProfile $profile): array => ['value' => $profile->value, 'label' => $profile->label()], DossierProfile::cases()),
        ]);
    }

    #[Route('/summary', name: 'app_library_distribute_summary', methods: ['GET'])]
    public function summary(Dossier $dossier, Request $request, DistributionPlanner $planner, #[CurrentUser] User $me): Response
    {
        $this->guard($dossier);

        return $this->render('library/distribute_summary.html.twig', [
            'template' => $dossier,
            'plan' => $this->plan($dossier, $request, $planner, $me, DistributionChoices::fromQuery($request)),
            'error' => null,
        ]);
    }

    #[Route('', name: 'app_library_distribute_launch', methods: ['POST'])]
    public function launch(Dossier $dossier, Request $request, DistributionPlanner $planner, Distributions $distributions, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->guard($dossier);
        if (!$this->isCsrfTokenValid('distribute-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('A stale form.');
        }
        $class = $planner->classFor($me, PostValue::nullableInt($request, 'class')) ?? throw new NotFoundHttpException('Not a class of the current year.');
        $plan = $planner->plan($dossier, $class, DistributionChoices::fromPost($request));
        try {
            $distribution = $distributions->launch($plan, $me, $clock->now());
        } catch (DistributionRefused $refused) {
            return $this->render('library/distribute_summary.html.twig', [
                'template' => $dossier,
                'plan' => $plan,
                'error' => $refused->getMessage(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        $this->addFlash('status', $plan->sentence().' Les copies se font en arrière-plan.');

        return $this->redirectToRoute('app_library_distribution', ['id' => $distribution->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Whoever reads a template may hand it out (TemplateVoter::DISTRIBUTE); anything else is a 404. */
    private function guard(Dossier $dossier): void
    {
        if (!$dossier->isTemplate() || !$this->isGranted(TemplateVoter::DISTRIBUTE, $dossier)) {
            throw new NotFoundHttpException('Not a template you may hand out.');
        }
    }

    /** The class asked (`class`), of the current year - a 404 otherwise -, or the teacher's first one. */
    private function schoolClass(Request $request, DistributionPlanner $planner, User $me): ?SchoolClass
    {
        $asked = QueryValue::nullableInt($request, 'class');
        $class = $planner->classFor($me, $asked);
        if (null !== $asked && null === $class) {
            throw new NotFoundHttpException('Not a class of the current year.');
        }

        return $class;
    }

    private function plan(Dossier $dossier, Request $request, DistributionPlanner $planner, User $me, DistributionChoices $choices): DistributionPlan
    {
        $class = $planner->classFor($me, QueryValue::nullableInt($request, 'class')) ?? throw new NotFoundHttpException('Not a class of the current year.');

        return $planner->plan($dossier, $class, $choices);
    }
}
