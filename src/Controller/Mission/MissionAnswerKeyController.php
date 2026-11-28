<?php

declare(strict_types=1);

namespace App\Controller\Mission;

use App\Entity\Identity\User;
use App\Enum\Document\DocumentState;
use App\Repository\Mission\MissionAnswerKeyRepository;
use App\Repository\Mission\MissionRepository;
use App\Service\Mission\AnswerKey\AnswerKeys;
use App\Service\Mission\MissionRefused;
use App\Service\Shared\PostValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Mission › Corrigé (step 2.9b; design-ux.md § 3.3 « Corrigé (saisi dans le PGI en mode corrigé,
 * tolérances) »): « Jouer la mission en mode corrigé » - the teacher's copy of the template -, then
 * « Enregistrer comme corrigé » reads it; the recorded entries and pièces are shown with the
 * tolerances, saved by F10 (equivalent accounts, date window, optional lines). The mission's author
 * alone (MissionVoter): a 404 for anybody else, students included - the answer key never leaves.
 */
#[Route('/teacher/library/missions/{id}/answer-key', requirements: ['id' => '\d+'])]
final class MissionAnswerKeyController extends AbstractController
{
    use MissionControllerTrait;

    #[Route('', name: 'app_mission_answer_key', methods: ['GET', 'POST'])]
    public function show(int $id, Request $request, MissionRepository $missions, MissionAnswerKeyRepository $answerKeys, AnswerKeys $doors, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $answerKey = $answerKeys->findOneByMission($mission);
        $form = [
            'equivalentAccounts' => implode("\n", array_map(static fn (array $group): string => implode(' ', $group), $answerKey?->getEquivalentAccounts() ?? [])),
            'dateWindow' => (string) ($answerKey?->getDateWindowDays() ?? 0),
        ];
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->refuseBadToken($mission, $request);
            $form = ['equivalentAccounts' => PostValue::trimmed($request, 'equivalentAccounts'), 'dateWindow' => PostValue::trimmed($request, 'dateWindow')];
            try {
                $doors->tolerate($mission, $form['equivalentAccounts'], $form['dateWindow'], array_values(PostValue::stringsByKey($request, 'optional')), $me);
                $this->addFlash('status', 'Tolérances du corrigé enregistrées.');

                return $this->redirectToRoute('app_mission_answer_key', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (MissionRefused $refused) {
                $errors = $refused->errors;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('mission/answer_key.html.twig', [
            'mission' => $mission,
            'mission_tab' => 'answer_key',
            'answer_key' => $answerKey,
            'form' => $form,
            'errors' => $errors,
            'piece_states' => array_combine(array_map(static fn (DocumentState $state): string => $state->value, DocumentState::cases()), array_map(static fn (DocumentState $state): string => $state->label(), DocumentState::cases())),
        ], new Response(status: $status));
    }

    #[Route('/play', name: 'app_mission_answer_key_play', methods: ['POST'])]
    public function play(int $id, Request $request, MissionRepository $missions, AnswerKeys $doors, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $this->refuseBadToken($mission, $request);
        try {
            $copy = $doors->play($mission, $me, $clock->now());
        } catch (MissionRefused $refused) {
            $this->addFlash('status', $refused->getMessage());

            return $this->redirectToRoute('app_mission_answer_key', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_dossier_dashboard', ['dossier' => $copy->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/record', name: 'app_mission_answer_key_record', methods: ['POST'])]
    public function record(int $id, Request $request, MissionRepository $missions, AnswerKeys $doors, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $this->refuseBadToken($mission, $request);
        try {
            $answerKey = $doors->record($mission, $me, $clock->now());
            $count = \count($answerKey->getEntries());
            $this->addFlash('status', \sprintf('Corrigé enregistré : %d écriture%s attendue%s.', $count, $count > 1 ? 's' : '', $count > 1 ? 's' : ''));
        } catch (MissionRefused $refused) {
            $this->addFlash('status', $refused->getMessage());
        }

        return $this->redirectToRoute('app_mission_answer_key', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
