<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\User;
use App\Repository\Identity\UserRepository;
use App\Service\Identity\Administrators;
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
 * Paramètres › Administration › Administrateurs (design-ux.md § 3.6): the list (since when, by
 * whom, or « liste d'amorçage »), « Désigner un administrateur » among the teachers, « Retirer » -
 * never a bootstrap administrator, never the last one. Administrators only.
 */
#[Route('/teacher/admin/administrators')]
final class AdministratorsController extends AbstractController
{
    #[Route('', name: 'app_admin_administrators', methods: ['GET'])]
    public function index(UserRepository $users, Administrators $administrators): Response
    {
        return $this->page($users, $administrators);
    }

    /** The autocomplete of « Désigner un administrateur »: teachers who are not administrators. */
    #[Route('/candidates', name: 'app_admin_administrators_candidates', methods: ['GET'])]
    public function candidates(Request $request, UserRepository $users): JsonResponse
    {
        $text = QueryValue::trimmed($request, 'q');
        if (mb_strlen($text) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(
            static fn (User $teacher): array => ['id' => $teacher->getId(), 'name' => $teacher->getDisplayName(), 'login' => $teacher->getLogin()],
            $users->searchTeachersNotAdministrators($text),
        ));
    }

    #[Route('/designate', name: 'app_admin_administrators_designate', methods: ['POST'])]
    public function designate(Request $request, UserRepository $users, Administrators $administrators, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-administrators-designate');
        $id = $request->request->getString('user');
        $teacher = ctype_digit($id) ? $users->find((int) $id) : null;
        $refusal = null === $teacher
            ? 'Choisissez un enseignant dans la liste.'
            : $administrators->designate($teacher, $me, $clock->now());
        if (null !== $refusal) {
            return $this->page($users, $administrators, $refusal, $request->request->getString('user_label'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        \assert(null !== $teacher);
        $this->addFlash('status', \sprintf('%s est désormais administrateur.', $teacher->getDisplayName()));

        return $this->redirectToRoute('app_admin_administrators', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/revoke', name: 'app_admin_administrators_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(#[MapEntity] User $administrator, Request $request, UserRepository $users, Administrators $administrators, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->checkToken($request, 'admin-administrators-revoke-'.$administrator->getId());
        $refusal = $administrators->revoke($administrator, $me, $clock->now());
        if (null !== $refusal) {
            return $this->page($users, $administrators, status: Response::HTTP_UNPROCESSABLE_ENTITY, listError: $refusal);
        }
        $this->addFlash('status', \sprintf('%s n’est plus administrateur.', $administrator->getDisplayName()));

        // Removing oneself leaves the administration: the next page is the teacher's home.
        return $this->redirectToRoute($administrator === $me ? 'app_teacher_home' : 'app_admin_administrators', status: Response::HTTP_SEE_OTHER);
    }

    private function page(UserRepository $users, Administrators $administrators, ?string $error = null, string $label = '', int $status = Response::HTTP_OK, ?string $listError = null): Response
    {
        $rows = [];
        foreach ($users->findAdministrators() as $administrator) {
            $rows[] = [
                'user' => $administrator,
                'bootstrap' => $administrators->isBootstrap($administrator),
                'refusal' => $administrators->revocationRefusal($administrator),
            ];
        }

        return $this->render('admin/administrators.html.twig', [
            'rows' => $rows,
            'error' => $error,
            'label' => $label,
            'list_error' => $listError,
        ], new Response(status: $status));
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
