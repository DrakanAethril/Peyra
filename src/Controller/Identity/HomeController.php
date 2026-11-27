<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** `/` sends each account to its own home: the teacher space, or the student's dossiers. */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(#[CurrentUser] User $user): RedirectResponse
    {
        return $this->redirectToRoute($user->isTeacher() ? 'app_teacher_home' : 'app_student_dossiers');
    }
}
