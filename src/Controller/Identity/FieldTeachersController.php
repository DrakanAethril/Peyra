<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\AuthorizedTeacher;
use App\Entity\Identity\User;
use App\Service\Identity\DirectoryUnavailable;
use App\Service\Identity\FieldTeachers;
use App\Service\Identity\TeacherAuthorizations;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paramètres › Administration › Enseignants de la filière (design-ux.md § 3.6): who may enter as a
 * teacher, « Ajouter un enseignant » by directory login, « Retirer » a teacher authorised by name.
 * Administrators only (access_control, ^/teacher/admin).
 */
#[Route('/teacher/admin/teachers')]
final class FieldTeachersController extends AbstractController
{
    #[Route('', name: 'app_admin_teachers', methods: ['GET'])]
    public function index(FieldTeachers $fieldTeachers): Response
    {
        return $this->page($fieldTeachers);
    }

    #[Route('/add', name: 'app_admin_teachers_add', methods: ['POST'])]
    public function add(Request $request, FieldTeachers $fieldTeachers, TeacherAuthorizations $authorizations, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-teachers-add');
        $login = trim($request->request->getString('login'));
        try {
            $refusal = $authorizations->add($login, $me, $clock->now());
        } catch (DirectoryUnavailable) {
            $refusal = 'L’annuaire de l’établissement ne répond pas. Réessayez dans un instant.';
        }
        if (null !== $refusal) {
            return $this->page($fieldTeachers, $login, $refusal, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('status', \sprintf('« %s » ajouté aux enseignants de la filière.', $login));

        return $this->redirectToRoute('app_admin_teachers', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/revoke', name: 'app_admin_teachers_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(#[MapEntity] AuthorizedTeacher $authorization, Request $request, TeacherAuthorizations $authorizations, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-teachers-revoke-'.$authorization->getId());
        if (!$authorization->isActive()) {
            throw new NotFoundHttpException('Already removed.');
        }
        $authorizations->revoke($authorization, $me, $clock->now());
        $this->addFlash('status', \sprintf('%s retiré des enseignants de la filière.', $authorization->getDisplayName()));

        return $this->redirectToRoute('app_admin_teachers', status: Response::HTTP_SEE_OTHER);
    }

    private function page(FieldTeachers $fieldTeachers, string $login = '', ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin/teachers.html.twig', [
            'entries' => $fieldTeachers->rows(),
            'login' => $login,
            'error' => $error,
        ], new Response(status: $status));
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
