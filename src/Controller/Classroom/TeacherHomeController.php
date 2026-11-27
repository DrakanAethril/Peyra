<?php

declare(strict_types=1);

namespace App\Controller\Classroom;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The teacher's home (design-ux.md § 3.3, « Accueil »): empty until the classes exist. */
final class TeacherHomeController extends AbstractController
{
    #[Route('/teacher', name: 'app_teacher_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('teacher/home.html.twig');
    }
}
