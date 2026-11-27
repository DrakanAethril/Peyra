<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\DossierListing;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Mes dossiers », where a student lands (design-ux.md §§ 3.2 and 4.1): every dossier they hold -
 * their own and their groups' - grouped by school year, all years, a card each. Empty until a
 * teacher distributes one; the student's class of the year shown, or, without one, the amber
 * banner that says a teacher will place them.
 */
final class MyDossiersController extends AbstractController
{
    #[Route('/dossiers', name: 'app_student_dossiers', methods: ['GET'])]
    public function index(SchoolYearRepository $years, EnrolmentRepository $enrolments, DossierRepository $dossiers, DossierListing $listing, #[CurrentUser] User $me): Response
    {
        $year = $years->findCurrent();

        return $this->render('student/dossiers.html.twig', [
            'enrolment' => null === $year ? null : $enrolments->findOneForStudentAndYear($me, $year),
            'groups' => $listing->byYear($dossiers->findForStudent($me)),
        ]);
    }
}
