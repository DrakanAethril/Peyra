<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\User;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\UserRepository;
use App\Security\Voter\SchoolClassVoter;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\Enrolments;
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
 * Classes › À placer (design-ux.md §§ 3.3 and 4.1, step 5): the students without a class in the
 * current year - the directory placed nobody, nobody passed them, a teacher took them out - with
 * the groups read when their account was created and their last known class. Tick, choose the
 * class, « Placer la sélection ». A student who left the field is marked so and listed apart.
 */
#[Route('/teacher/classes/unplaced')]
final class UnplacedController extends AbstractController
{
    #[Route('', name: 'app_teacher_unplaced', methods: ['GET'])]
    public function index(Request $request, ClassBoard $board, UserRepository $users, EnrolmentRepository $enrolments): Response
    {
        $year = $board->currentYear();
        $departed = 'departed' === QueryValue::string($request, 'show');
        $students = null === $year ? [] : $users->findStudentsWithoutEnrolment($year, $departed);
        $ids = array_map(static fn (User $s): int => (int) $s->getId(), $students);

        return $this->render('classes/unplaced.html.twig', [
            'year' => $year,
            'departed' => $departed,
            'students' => $students,
            'latest' => $enrolments->findLatestByStudents($ids),
            'classes' => null === $year ? [] : $board->rows($year),
            'administrators' => $users->findAdministrators(),
            'counts' => null === $year ? ['waiting' => 0, 'departed' => 0] : [
                'waiting' => $departed ? $users->countStudentsWithoutEnrolment($year) : \count($students),
                'departed' => $departed ? \count($students) : \count($users->findStudentsWithoutEnrolment($year, true)),
            ],
        ]);
    }

    #[Route('/place', name: 'app_teacher_unplaced_place', methods: ['POST'])]
    public function place(Request $request, ClassBoard $board, UserRepository $users, SchoolClassRepository $classes, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request);
        $year = $board->currentYear();
        $classId = PostValue::nullableInt($request, 'class');
        $class = null === $classId ? null : $classes->find($classId);
        $students = $users->findStudentsByIds(PostValue::intList($request, 'students'));
        if (null === $year || null === $class || $class->getSchoolYear() !== $year) {
            $this->addFlash('status', 'Choisissez la classe où placer les étudiants cochés.');
        } elseif ([] === $students) {
            $this->addFlash('status', 'Cochez les étudiants à placer.');
        } else {
            $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $class);
            $this->addFlash('status', $placements->place($students, $class, $me, $clock->now())->sentence($class->getName()));
        }

        return $this->redirectToRoute('app_teacher_unplaced', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/depart', name: 'app_teacher_unplaced_depart', methods: ['POST'])]
    public function depart(Request $request, UserRepository $users, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request);
        $count = $placements->markDeparted($users->findStudentsByIds(PostValue::intList($request, 'students')), $me, $clock->now());
        $this->addFlash('status', 0 === $count ? 'Cochez les étudiants sortis de la filière.' : \sprintf('%d %s de la filière ; %s et %s dossiers restent.', $count, $count > 1 ? 'étudiants marqués sortis' : 'étudiant marqué sorti', $count > 1 ? 'leurs comptes' : 'son compte', $count > 1 ? 'leurs' : 'ses'));

        return $this->redirectToRoute('app_teacher_unplaced', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/restore', name: 'app_teacher_unplaced_restore', methods: ['POST'])]
    public function restore(Request $request, UserRepository $users, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request);
        $count = $placements->restore($users->findStudentsByIds(PostValue::intList($request, 'students')), $me, $clock->now());
        $this->addFlash('status', 0 === $count ? 'Cochez les étudiants à remettre à placer.' : \sprintf('%d %s à placer.', $count, $count > 1 ? 'étudiants remis' : 'étudiant remis'));

        return $this->redirectToRoute('app_teacher_unplaced', ['show' => 'departed'], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('unplaced', $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
