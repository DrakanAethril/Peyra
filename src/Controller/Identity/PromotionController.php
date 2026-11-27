<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use App\Security\Voter\SchoolClassVoter;
use App\Service\Identity\Promotion;
use App\Service\Identity\PromotionChoice;
use App\Service\Identity\YearPreparation;
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
 * Classes › Passage de classe (design-ux.md § 4.12, gabarit E): 1. the class of last year
 * (« CG1 2026-2027 → CG2 2027-2028 »); 2. its students, all ticked - untick those who repeat
 * (another class of the new year), those not placed, those who leave; 3. the summary, then
 * « Passer les étudiants ». Steps 2 and 3 are GET pages carrying the choices; the gesture is the
 * POST of step 3. Made again without harm: a student already in the new year is greyed.
 */
#[Route('/teacher/classes/promotion')]
final class PromotionController extends AbstractController
{
    #[Route('', name: 'app_teacher_promotion', methods: ['GET'])]
    public function choose(Promotion $promotion, YearPreparation $preparation, UserRepository $users): Response
    {
        return $this->render('classes/promotion_choose.html.twig', [
            'sources' => $promotion->sources(),
            'checks' => $preparation->checks(),
            'administrators' => $users->findAdministrators(),
        ]);
    }

    #[Route('/{id}', name: 'app_teacher_promotion_students', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function students(#[MapEntity] SchoolClass $source, Request $request, Promotion $promotion): Response
    {
        $this->guard($source);

        return $this->render('classes/promotion_students.html.twig', [
            'plan' => $promotion->plan($source, $this->choices($request, true)),
            'targets' => $promotion->targetClasses($source),
        ]);
    }

    #[Route('/{id}/summary', name: 'app_teacher_promotion_summary', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function summary(#[MapEntity] SchoolClass $source, Request $request, Promotion $promotion): Response
    {
        $this->guard($source);

        return $this->render('classes/promotion_summary.html.twig', [
            'plan' => $promotion->plan($source, $this->choices($request, false) ?? []),
        ]);
    }

    #[Route('/{id}', name: 'app_teacher_promotion_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(#[MapEntity] SchoolClass $source, Request $request, Promotion $promotion, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->guard($source);
        if (!$this->isCsrfTokenValid('promotion-'.$source->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
        $ticked = PostValue::intList($request, 'pass');
        $choices = [];
        foreach (PostValue::stringsById($request, 'other') as $id => $other) {
            $choices[$id] = PromotionChoice::fromForm(false, $other);
        }
        foreach ($ticked as $id) {
            $choices[$id] = PromotionChoice::next();
        }
        $plan = $promotion->plan($source, $choices);
        $promotion->apply($plan, $me, $clock->now());
        $this->addFlash('status', $plan->sentence().' Les dossiers ne sont pas touchés.');

        return $this->redirectToRoute('app_teacher_class', ['id' => $plan->next->getId()], Response::HTTP_SEE_OTHER);
    }

    /** A passage starts from a class naming a next class that is not history, and the teacher may place there. */
    private function guard(SchoolClass $source): void
    {
        $next = $source->getNextClass();
        if (null === $next) {
            throw new NotFoundHttpException('This class names no next class.');
        }
        $this->denyAccessUnlessGranted(SchoolClassVoter::VIEW, $source);
        $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $next);
    }

    /**
     * The choices carried in the query string (`pass[]`, `other[id]`), or null on a first display
     * (no `step` parameter), when everybody is ticked.
     *
     * @return array<int, PromotionChoice>|null
     */
    private function choices(Request $request, bool $firstDisplayAllowed): ?array
    {
        if ($firstDisplayAllowed && '' === QueryValue::string($request, 'step')) {
            return null;
        }
        $choices = [];
        foreach (PostValue::queryStringsById($request, 'other') as $id => $other) {
            $choices[$id] = PromotionChoice::fromForm(false, $other);
        }
        foreach (QueryValue::intList($request, 'pass') as $id) {
            $choices[$id] = PromotionChoice::next();
        }

        return $choices;
    }
}
