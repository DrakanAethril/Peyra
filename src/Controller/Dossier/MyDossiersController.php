<?php

declare(strict_types=1);

namespace App\Controller\Dossier;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mes dossiers », where a student lands (design-ux.md § 4.1, step 3): empty until a teacher
 * distributes a dossier.
 */
final class MyDossiersController extends AbstractController
{
    #[Route('/dossiers', name: 'app_student_dossiers', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('student/dossiers.html.twig');
    }
}
