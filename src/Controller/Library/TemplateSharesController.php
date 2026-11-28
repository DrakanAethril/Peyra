<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierShare;
use App\Entity\Identity\User;
use App\Enum\Shared\BtsProcess;
use App\Repository\Dossier\DossierShareRepository;
use App\Repository\Identity\UserRepository;
use App\Security\Voter\TemplateVoter;
use App\Service\Dossier\DossierContents;
use App\Service\Dossier\Library\TemplateCardDraft;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Dossier\Library\TemplateShares;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A template's shares, on its fiche (conception § 4, « lecture ou modification »): the author
 * picks a colleague (autocomplete, CLAUDE.md « Forms »), gives the reading or the change, changes
 * the right, takes it back. TemplateVoter::SHARE: the author alone.
 */
final class TemplateSharesController extends AbstractController
{
    #[Route('/teacher/library/{dossier}/colleagues', name: 'app_library_colleagues', requirements: ['dossier' => '\d+'], methods: ['GET'])]
    public function colleagues(Dossier $dossier, Request $request, UserRepository $users, #[CurrentUser] User $me): JsonResponse
    {
        $this->allowed($dossier);
        $text = QueryValue::trimmed($request, 'q');
        if (mb_strlen($text) < 2) {
            return $this->json([]);
        }

        return $this->json(array_map(
            static fn (User $teacher): array => ['id' => $teacher->getId(), 'name' => $teacher->getDisplayName(), 'login' => $teacher->getLogin()],
            $users->searchTeachers($text, $me),
        ));
    }

    #[Route('/teacher/library/{dossier}/shares', name: 'app_library_share', requirements: ['dossier' => '\d+'], methods: ['POST'])]
    public function share(Dossier $dossier, Request $request, TemplateShares $templateShares, UserRepository $users, DossierShareRepository $shares, TemplateLibrary $library, DossierContents $contents, EntityManagerInterface $entityManager, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->allowed($dossier);
        $this->checkToken($request, $dossier);
        $id = PostValue::trimmed($request, 'colleague');
        $colleague = ctype_digit($id) ? $users->find((int) $id) : null;
        $canEdit = 'edit' === PostValue::trimmed($request, 'right');
        $refusal = $templateShares->share($dossier, $colleague, $canEdit, $me, $clock->now());
        if (null !== $refusal) {
            $card = $library->card($me, $dossier) ?? throw new NotFoundHttpException('Not a template of the library.');

            return $this->render('library/template.html.twig', [
                'dossier' => $dossier,
                'card' => $card,
                'draft' => TemplateCardDraft::of($dossier),
                'contents' => $contents->of($dossier),
                'shares' => $shares->findByDossier($dossier),
                'share_error' => $refusal,
                'share_label' => PostValue::trimmed($request, 'colleague_label'),
                'levels' => TemplateController::levels(),
                'processes' => BtsProcess::cases(),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        $entityManager->flush();
        \assert(null !== $colleague);
        $this->addFlash('status', \sprintf('Modèle partagé avec %s (%s).', $colleague->getDisplayName(), $canEdit ? 'modification' : 'lecture'));

        return $this->redirectToRoute('app_library_template', ['dossier' => $dossier->getId(), '_fragment' => 'partages'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/teacher/library/{dossier}/shares/{share}/right', name: 'app_library_share_right', requirements: ['dossier' => '\d+', 'share' => '\d+'], methods: ['POST'])]
    public function right(Dossier $dossier, DossierShare $share, Request $request, TemplateShares $templateShares, EntityManagerInterface $entityManager, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $this->allowed($dossier, $share);
        $this->checkToken($request, $dossier);
        $templateShares->share($dossier, $share->getTeacher(), 'edit' === PostValue::trimmed($request, 'right'), $me, $clock->now());
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Droit de %s enregistré.', $share->getTeacher()->getDisplayName()));

        return $this->redirectToRoute('app_library_template', ['dossier' => $dossier->getId(), '_fragment' => 'partages'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/teacher/library/{dossier}/shares/{share}/revoke', name: 'app_library_share_revoke', requirements: ['dossier' => '\d+', 'share' => '\d+'], methods: ['POST'])]
    public function revoke(Dossier $dossier, DossierShare $share, Request $request, TemplateShares $templateShares, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $this->allowed($dossier, $share);
        $this->checkToken($request, $dossier);
        $name = $share->getTeacher()->getDisplayName();
        $templateShares->revoke($share, $me);
        $entityManager->flush();
        $this->addFlash('status', \sprintf('Partage avec %s retiré.', $name));

        return $this->redirectToRoute('app_library_template', ['dossier' => $dossier->getId(), '_fragment' => 'partages'], Response::HTTP_SEE_OTHER);
    }

    private function allowed(Dossier $dossier, ?DossierShare $share = null): void
    {
        if (!$dossier->isTemplate() || !$this->isGranted(TemplateVoter::SHARE, $dossier) || (null !== $share && $share->getDossier() !== $dossier)) {
            throw new NotFoundHttpException('Not yours to share.');
        }
    }

    private function checkToken(Request $request, Dossier $dossier): void
    {
        if (!$this->isCsrfTokenValid('library-shares-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('A stale form.');
        }
    }
}
