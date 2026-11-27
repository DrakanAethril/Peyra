<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Identity\User;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolYearRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Mes dossiers », where a student lands (design-ux.md § 4.1, steps 3 and 4): empty until a
 * teacher distributes a dossier, with the student's class of the year shown - or, without one, the
 * amber banner that says a teacher will place them.
 */
final class MyDossiersController extends AbstractController
{
    #[Route('/dossiers', name: 'app_student_dossiers', methods: ['GET'])]
    public function index(SchoolYearRepository $years, EnrolmentRepository $enrolments, #[CurrentUser] User $me): Response
    {
        $year = $years->findCurrent();

        return $this->render('student/dossiers.html.twig', [
            'enrolment' => null === $year ? null : $enrolments->findOneForStudentAndYear($me, $year),
        ]);
    }
}
