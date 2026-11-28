<?php

declare(strict_types=1);

namespace App\Controller\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Mission\MissionAssignmentRepository;
use App\Repository\Mission\MissionRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Mission\MissionRefused;
use App\Service\Mission\MissionSheet;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bibliothèque › Missions (step 2.9a; design-ux.md § 2.6, « modèles de mission · composer une
 * mission »): the teacher's missions, a new one on a template they read, a copy of one.
 */
#[Route('/teacher/library/missions')]
final class MissionsController extends AbstractController
{
    use MissionControllerTrait;

    #[Route('', name: 'app_library_missions', methods: ['GET'])]
    public function index(MissionRepository $missions, MissionAssignmentRepository $assignments, #[CurrentUser] User $me): Response
    {
        $mine = $missions->findByAuthor($me);
        $given = [];
        foreach ($mine as $mission) {
            $given[(int) $mission->getId()] = \count($assignments->findByMission($mission));
        }

        return $this->render('mission/index.html.twig', ['missions' => $mine, 'given' => $given]);
    }

    #[Route('/new', name: 'app_mission_new', methods: ['GET', 'POST'])]
    public function new(Request $request, TemplateLibrary $library, MissionSheet $sheet, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $templates = array_map(static fn (TemplateCard $card): Dossier => $card->template, $library->cards($me));
        $chosen = $request->isMethod('POST') ? PostValue::nullableInt($request, 'template') : QueryValue::nullableInt($request, 'template');
        $title = PostValue::trimmed($request, 'title');
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mission-new', $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Bad token.');
            }
            $template = null;
            foreach ($templates as $one) {
                if ($one->getId() === $chosen && $this->isGranted(DossierVoter::VIEW, $one)) {
                    $template = $one;
                }
            }
            try {
                if (null === $template) {
                    throw MissionRefused::of(['template' => 'Choisissez le dossier modèle sur lequel la mission se joue.']);
                }
                $mission = $sheet->create($template, $title, $me, $clock->now());
                $this->addFlash('status', \sprintf('Mission « %s » créée : composez-la onglet par onglet.', $mission->getTitle()));

                return $this->redirectToRoute('app_mission', ['id' => $mission->getId()], Response::HTTP_SEE_OTHER);
            } catch (MissionRefused $refused) {
                $errors = $refused->errors;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('mission/new.html.twig', [
            'template_options' => array_map(static fn (Dossier $one): array => ['value' => (string) $one->getId(), 'label' => $one->getLibraryTitle()], $templates),
            'chosen' => null === $chosen ? '' : (string) $chosen,
            'title' => $title,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    #[Route('/{id}/duplicate', name: 'app_mission_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(int $id, Request $request, MissionRepository $missions, MissionSheet $sheet, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $this->refuseBadToken($mission, $request);
        try {
            $copy = $sheet->duplicate($mission, $me, $clock->now());
            $this->addFlash('status', \sprintf('Mission dupliquée : « %s ».', $copy->getTitle()));

            return $this->redirectToRoute('app_mission', ['id' => $copy->getId()], Response::HTTP_SEE_OTHER);
        } catch (MissionRefused $refused) {
            $this->addFlash('status', $refused->getMessage());

            return $this->redirectToRoute('app_mission', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
    }
}
