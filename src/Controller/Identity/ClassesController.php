<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\TeacherClassRepository;
use App\Repository\Identity\UserRepository;
use App\Security\Voter\SchoolClassVoter;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\Enrolments;
use App\Service\Shared\PostValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Classes › Classes de l'année, a class and its students, Années précédentes (design-ux.md §§ 2.6
 * and 3.3): every teacher of the field reads every class and places students in the classes of
 * the current year (SchoolClassVoter); a past year's class is read only.
 */
#[Route('/teacher/classes')]
final class ClassesController extends AbstractController
{
    #[Route('', name: 'app_teacher_classes', methods: ['GET'])]
    public function year(ClassBoard $board, UserRepository $users, #[CurrentUser] User $me): Response
    {
        $year = $board->currentYear();

        return $this->render('classes/year.html.twig', [
            'year' => $year,
            'rows' => null === $year ? [] : $board->rows($year, $me),
            'administrators' => $users->findAdministrators(),
        ]);
    }

    #[Route('/past', name: 'app_teacher_classes_past', methods: ['GET'])]
    public function past(ClassBoard $board): Response
    {
        $years = [];
        foreach ($board->pastYears() as $year) {
            $years[] = ['year' => $year, 'rows' => $board->rows($year)];
        }

        return $this->render('classes/past.html.twig', ['years' => $years]);
    }

    #[Route('/{id}', name: 'app_teacher_class', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity] SchoolClass $class, ClassBoard $board, EnrolmentRepository $enrolments, SchoolClassRepository $classes, TeacherClassRepository $teachers, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::VIEW, $class);

        return $this->render('classes/class.html.twig', [
            'class' => $class,
            'row' => $board->row($class, $me),
            'enrolments' => $enrolments->findByClass($class),
            'teachers' => $teachers->findByClass($class),
            'others' => array_values(array_filter($classes->findByYear($class->getSchoolYear()), static fn (SchoolClass $c): bool => $c !== $class)),
            'previous' => $classes->findLeadingTo($class),
            'can_place' => $this->isGranted(SchoolClassVoter::PLACE, $class),
            'history' => !$this->isGranted(SchoolClassVoter::PLACE, $class),
        ]);
    }

    /** « Ajouter un étudiant »: one student chosen in the autocomplete, enrolled or moved here. */
    #[Route('/{id}/add', name: 'app_teacher_class_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function add(#[MapEntity] SchoolClass $class, Request $request, UserRepository $users, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $class);
        $this->checkToken($request, 'class-add-'.$class->getId());
        $id = PostValue::nullableInt($request, 'student');
        $students = null === $id ? [] : $users->findStudentsByIds([$id]);
        if ([] === $students) {
            $this->addFlash('status', 'Choisissez un étudiant dans la liste.');
        } else {
            $this->addFlash('status', $placements->place($students, $class, $me, $clock->now())->sentence($class->getName()));
        }

        return $this->redirectToRoute('app_teacher_class', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    /** « Déplacer » the ticked students to another class of the same year. */
    #[Route('/{id}/move', name: 'app_teacher_class_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(#[MapEntity] SchoolClass $class, Request $request, SchoolClassRepository $classes, EnrolmentRepository $enrolments, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $class);
        $this->checkToken($request, 'class-lot-'.$class->getId());
        $targetId = PostValue::nullableInt($request, 'target');
        $target = null === $targetId ? null : $classes->find($targetId);
        $students = $this->tickedStudents($request, $class, $enrolments);
        if (null === $target || $target->getSchoolYear() !== $class->getSchoolYear() || $target === $class) {
            $this->addFlash('status', 'Choisissez la classe où déplacer les étudiants cochés.');
        } elseif ([] === $students) {
            $this->addFlash('status', 'Cochez les étudiants à déplacer.');
        } else {
            $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $target);
            $this->addFlash('status', $placements->place($students, $target, $me, $clock->now())->sentence($target->getName()));
        }

        return $this->redirectToRoute('app_teacher_class', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    /** « Retirer de la classe »: no class this year any more; account and dossiers untouched. */
    #[Route('/{id}/remove', name: 'app_teacher_class_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(#[MapEntity] SchoolClass $class, Request $request, EnrolmentRepository $enrolments, Enrolments $placements, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->denyAccessUnlessGranted(SchoolClassVoter::PLACE, $class);
        $this->checkToken($request, 'class-lot-'.$class->getId());
        $ids = PostValue::intList($request, 'students');
        $ticked = array_values(array_filter($enrolments->findByClass($class), static fn ($e): bool => \in_array($e->getStudent()->getId(), $ids, true)));
        if ([] === $ticked) {
            $this->addFlash('status', 'Cochez les étudiants à retirer.');
        } else {
            $count = $placements->remove($ticked, $me, $clock->now());
            $this->addFlash('status', \sprintf('%d %s de %s ; %s dans « À placer ».', $count, $count > 1 ? 'étudiants retirés' : 'étudiant retiré', $class->getName(), $count > 1 ? 'ils sont' : 'il est'));
        }

        return $this->redirectToRoute('app_teacher_class', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    /** @return list<User> the ticked students who are in this class */
    private function tickedStudents(Request $request, SchoolClass $class, EnrolmentRepository $enrolments): array
    {
        $ids = PostValue::intList($request, 'students');
        $students = [];
        foreach ($enrolments->findByClass($class) as $enrolment) {
            if (\in_array($enrolment->getStudent()->getId(), $ids, true)) {
                $students[] = $enrolment->getStudent();
            }
        }

        return $students;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
