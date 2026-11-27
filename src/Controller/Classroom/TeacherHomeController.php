<?php

declare(strict_types=1);

namespace App\Controller\Classroom;

use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\YearPreparation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The teacher's home (design-ux.md § 3.3, « Accueil », gabarit T): their classes, the students to
 * place, and for an administrator what the year still needs. A year without classes says what to
 * do (§ 4.1, step 7). Missions, copies and distributions join it with their lots.
 */
final class TeacherHomeController extends AbstractController
{
    #[Route('/teacher', name: 'app_teacher_home', methods: ['GET'])]
    public function home(ClassBoard $board, UserRepository $users, YearPreparation $preparation, #[CurrentUser] User $me): Response
    {
        $year = $board->currentYear();
        $rows = null === $year ? [] : $board->rows($year, $me);

        return $this->render('teacher/home.html.twig', [
            'year' => $year,
            'rows' => $rows,
            'mine' => array_values(array_filter($rows, static fn ($r): bool => $r->mine)),
            'unplaced' => null === $year ? 0 : $users->countStudentsWithoutEnrolment($year),
            'administrators' => $users->findAdministrators(),
            'checks' => $this->isGranted('ROLE_ADMIN') ? $preparation->checks() : [],
        ]);
    }
}
