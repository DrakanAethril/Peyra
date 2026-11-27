<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Identity\ClassLevel;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\TeacherClassRepository;
use App\Repository\Identity\UserRepository;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\SchoolClasses;
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
 * Paramètres › Administration › Classes (design-ux.md § 2.6, « classes et classe suivante »): the
 * classes of a year, « Nouvelle classe », and a class's sheet - name, level, next class, teachers,
 * « Supprimer » for a class that never had a student. Administrators only.
 */
#[Route('/teacher/admin/classes')]
final class SchoolClassesAdminController extends AbstractController
{
    #[Route('', name: 'app_admin_classes', methods: ['GET'])]
    public function index(Request $request, SchoolYearRepository $years, ClassBoard $board): Response
    {
        return $this->page($request, $years, $board);
    }

    #[Route('', name: 'app_admin_classes_create', methods: ['POST'])]
    public function create(Request $request, SchoolYearRepository $years, ClassBoard $board, SchoolClasses $classes, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-classes-create');
        $yearId = PostValue::nullableInt($request, 'year');
        $year = null === $yearId ? null : $years->find($yearId);
        if (null === $year) {
            throw new NotFoundHttpException('Unknown year.');
        }
        $name = PostValue::trimmed($request, 'name');
        $level = ClassLevel::tryFrom((int) PostValue::nullableInt($request, 'level'));
        $result = $classes->create($year, $name, $level, $me, $clock->now());
        if (\is_string($result)) {
            return $this->page($request, $years, $board, $year, $name, $level, $result, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('status', \sprintf('Classe %s créée.', $result->getLabel()));

        return $this->redirectToRoute('app_admin_classes', ['year' => $year->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'app_admin_class', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(#[MapEntity] SchoolClass $class, SchoolClassRepository $classRepository, TeacherClassRepository $teachers, SchoolClasses $classes): Response
    {
        return $this->sheet($class, $classRepository, $teachers, $classes);
    }

    #[Route('/{id}', name: 'app_admin_class_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(#[MapEntity] SchoolClass $class, Request $request, SchoolClassRepository $classRepository, TeacherClassRepository $teachers, SchoolClasses $classes, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-class-'.$class->getId());
        $name = PostValue::trimmed($request, 'name');
        $level = ClassLevel::tryFrom((int) PostValue::nullableInt($request, 'level'));
        $nextId = PostValue::nullableInt($request, 'next');
        $next = null === $nextId ? null : $classRepository->find($nextId);
        $form = ['name' => $name, 'level' => $level, 'next' => $next];
        $nextRefusal = $classes->nextClassRefusal($class, $next);
        if (null !== $nextRefusal) {
            return $this->sheet($class, $classRepository, $teachers, $classes, $form, nextError: $nextRefusal, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $refusal = $classes->update($class, $name, $level, $next, $me, $clock->now());
        if (null !== $refusal) {
            return $this->sheet($class, $classRepository, $teachers, $classes, $form, $refusal, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('status', \sprintf('Classe %s enregistrée.', $class->getLabel()));

        return $this->redirectToRoute('app_admin_class', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/delete', name: 'app_admin_class_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(#[MapEntity] SchoolClass $class, Request $request, SchoolClassRepository $classRepository, TeacherClassRepository $teachers, SchoolClasses $classes, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-class-delete-'.$class->getId());
        $year = $class->getSchoolYear();
        $label = $class->getLabel();
        $refusal = $classes->delete($class, $me, $clock->now());
        if (null !== $refusal) {
            return $this->sheet($class, $classRepository, $teachers, $classes, sheetError: $refusal, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('status', \sprintf('Classe %s supprimée.', $label));

        return $this->redirectToRoute('app_admin_classes', ['year' => $year->getId()], Response::HTTP_SEE_OTHER);
    }

    /** The autocomplete of « Ajouter un enseignant »: teacher accounts whose name or login contains the text. */
    #[Route('/teachers/search', name: 'app_admin_classes_teacher_search', methods: ['GET'])]
    public function searchTeachers(Request $request, UserRepository $users): JsonResponse
    {
        $text = mb_strtolower(QueryValue::trimmed($request, 'q'));
        if (mb_strlen($text) < 2) {
            return $this->json([]);
        }
        $found = array_filter($users->findTeachers(), static fn (User $t): bool => str_contains(mb_strtolower($t->getDisplayName()), $text) || str_contains(mb_strtolower($t->getLogin()), $text));

        return $this->json(array_values(array_map(static fn (User $t): array => ['id' => $t->getId(), 'name' => $t->getDisplayName(), 'login' => $t->getLogin()], $found)));
    }

    #[Route('/{id}/teachers', name: 'app_admin_class_teacher_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addTeacher(#[MapEntity] SchoolClass $class, Request $request, UserRepository $users, SchoolClassRepository $classRepository, TeacherClassRepository $teachers, SchoolClasses $classes, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-class-teacher-'.$class->getId());
        $id = PostValue::nullableInt($request, 'teacher');
        $teacher = null === $id ? null : $users->find($id);
        $refusal = null === $teacher ? 'Choisissez un enseignant dans la liste.' : $classes->addTeacher($class, $teacher, $me, $clock->now());
        if (null !== $refusal) {
            return $this->sheet($class, $classRepository, $teachers, $classes, teacherError: $refusal, teacherLabel: PostValue::trimmed($request, 'teacher_label'), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        \assert(null !== $teacher);
        $this->addFlash('status', \sprintf('%s enseigne en %s.', $teacher->getDisplayName(), $class->getName()));

        return $this->redirectToRoute('app_admin_class', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/teachers/{assignment}/remove', name: 'app_admin_class_teacher_remove', requirements: ['id' => '\d+', 'assignment' => '\d+'], methods: ['POST'])]
    public function removeTeacher(#[MapEntity(id: 'id')] SchoolClass $class, #[MapEntity(id: 'assignment')] TeacherClass $assignment, Request $request, SchoolClasses $classes, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-class-teacher-remove-'.$assignment->getId());
        if ($assignment->getSchoolClass() !== $class) {
            throw new NotFoundHttpException('Not a teacher of this class.');
        }
        $name = $assignment->getTeacher()->getDisplayName();
        $classes->removeTeacher($assignment, $me, $clock->now());
        $this->addFlash('status', \sprintf('%s n’enseigne plus en %s.', $name, $class->getName()));

        return $this->redirectToRoute('app_admin_class', ['id' => $class->getId()], Response::HTTP_SEE_OTHER);
    }

    private function page(Request $request, SchoolYearRepository $years, ClassBoard $board, ?SchoolYear $year = null, string $name = '', ?ClassLevel $level = null, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $all = $years->findAllNewestFirst();
        if (null === $year) {
            $wanted = QueryValue::nullableInt($request, 'year');
            foreach ($all as $candidate) {
                if ($candidate->getId() === $wanted) {
                    $year = $candidate;
                }
            }
            $year ??= $years->findCurrent() ?? ($all[0] ?? null);
        }

        return $this->render('admin/classes.html.twig', [
            'years' => $all,
            'year' => $year,
            'class_rows' => null === $year ? [] : $board->rows($year),
            'name' => $name,
            'level' => $level,
            'error' => $error,
        ], new Response(status: $status));
    }

    /** @param array{name: string, level: ?ClassLevel, next: ?SchoolClass}|null $form */
    private function sheet(SchoolClass $class, SchoolClassRepository $classRepository, TeacherClassRepository $teachers, SchoolClasses $classes, ?array $form = null, ?string $error = null, ?string $sheetError = null, ?string $teacherError = null, string $teacherLabel = '', ?string $nextError = null, int $status = Response::HTTP_OK): Response
    {
        $later = [];
        foreach ($classRepository->findAll() as $candidate) {
            if ($candidate->getSchoolYear()->getStartsOn() > $class->getSchoolYear()->getStartsOn()) {
                $later[] = $candidate;
            }
        }
        usort($later, static fn (SchoolClass $a, SchoolClass $b): int => [$a->getSchoolYear()->getStartsOn(), $a->getLevel()->value, $a->getName()] <=> [$b->getSchoolYear()->getStartsOn(), $b->getLevel()->value, $b->getName()]);

        return $this->render('admin/class.html.twig', [
            'class' => $class,
            'form' => $form ?? ['name' => $class->getName(), 'level' => $class->getLevel(), 'next' => $class->getNextClass()],
            'later' => $later,
            'teachers' => $teachers->findByClass($class),
            'deletion_refusal' => $classes->deletionRefusal($class),
            'error' => $error,
            'sheet_error' => $sheetError,
            'teacher_error' => $teacherError,
            'next_error' => $nextError,
            'teacher_label' => $teacherLabel,
            'levels' => ClassLevel::cases(),
        ], new Response(status: $status));
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
