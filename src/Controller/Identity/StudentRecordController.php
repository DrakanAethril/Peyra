<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\User;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\UserRepository;
use App\Security\Voter\SchoolClassVoter;
use App\Security\Voter\StudentVoter;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\Enrolments;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The student's record (design-ux.md § 3.3, « Fiche étudiant »): their enrolments year by year,
 * what the directory said when their account was created, and « Changer de classe » for the
 * current year (design-ux.md § 4.1, step 5) - the dossiers do not move. Dossiers, missions and
 * positioning join it with their lots. Every teacher of the field (StudentVoter).
 */
#[Route('/teacher/students')]
final class StudentRecordController extends AbstractController
{
    /** The autocomplete of « Ajouter un étudiant »: students whose name or login contains the text. */
    #[Route('/search', name: 'app_teacher_students_search', methods: ['GET'])]
    public function search(Request $request, UserRepository $users): JsonResponse
    {
        $text = QueryValue::trimmed($request, 'q');
        if (mb_strlen($text) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(
            static fn (User $student): array => ['id' => $student->getId(), 'name' => $student->getSortName(), 'login' => $student->getLogin()],
            $users->searchStudents($text),
        ));
    }

    #[Route('/{id}', name: 'app_teacher_student', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity] User $student, ClassBoard $board, EnrolmentRepository $enrolments): Response
    {
        $this->denyAccessUnlessGranted(StudentVoter::VIEW, $student);
        $year = $board->currentYear();
        $history = $enrolments->findByStudent($student);
        $current = null;
        foreach ($history as $enrolment) {
            if ($enrolment->getSchoolYear() === $year) {
                $current = $enrolment;
            }
        }

        return $this->render('classes/student.html.twig', [
            'student' => $student,
            'year' => $year,
            'history' => $history,
            'current' => $current,
            'classes' => null === $year ? [] : $board->rows($year),
        ]);
    }

    /** « Changer de classe » for the current year, or « Retirer de la classe » (`class` = none). */
    #[Route('/{id}/class', name: 'app_teacher_student_class', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function changeClass(#[MapEntity] User $student, Request $request, ClassBoard $board, SchoolClassRepository $classes, EnrolmentRepository $enrolments, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(StudentVoter::PLACE, $student);
        if (!$this->isCsrfTokenValid('student-class-'.$student->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
        $year = $board->currentYear();
        if (null === $year) {
            throw new NotFoundHttpException('No current year.');
        }
        $choice = PostValue::trimmed($request, 'class');
        if ('none' === $choice) {
            $enrolment = $enrolments->findOneForStudentAndYear($student, $year);
            if (null !== $enrolment) {
                $placements->remove([$enrolment], $me, $clock->now());
                $this->addFlash('status', \sprintf('%s n’a plus de classe en %s ; ses dossiers restent.', $student->getDisplayName(), $year->getLabel()));
            }
        } else {
            $class = ctype_digit($choice) ? $classes->find((int) $choice) : null;
            if (null === $class || $class->getSchoolYear() !== $year) {
                $this->addFlash('status', 'Choisissez une classe de l’année.');
            } else {
                $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $class);
                $this->addFlash('status', $placements->place([$student], $class, $me, $clock->now())->sentence($class->getName()));
            }
        }

        return $this->redirectToRoute('app_teacher_student', ['id' => $student->getId()], Response::HTTP_SEE_OTHER);
    }
}
