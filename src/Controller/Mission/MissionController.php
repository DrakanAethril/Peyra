<?php

declare(strict_types=1);

namespace App\Controller\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\User;
use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAssignment;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Mission\MissionModule;
use App\Enum\Mission\MissionTaskCheck;
use App\Repository\Document\DocumentRepository;
use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Dossier\DistributionRepository;
use App\Repository\Mission\MissionAssignmentRepository;
use App\Repository\Mission\MissionProgressRepository;
use App\Repository\Mission\MissionRepository;
use App\Security\Voter\DistributionVoter;
use App\Security\Voter\DossierVoter;
use App\Service\Document\Documents;
use App\Service\Document\Preparation\PiecePreparation;
use App\Service\Dossier\DossierContext;
use App\Service\Mission\AnswerKey\AnswerKeyStatus;
use App\Service\Mission\MissionAssignments;
use App\Service\Mission\MissionRefused;
use App\Service\Mission\MissionSheet;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use App\Service\Shared\Referential\Referential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A mission of the library, tab by tab (step 2.9a; design-ux.md § 3.3 « Mission (composition) », gabarit
 * F à onglets, F10 on each): Général (title, statement, modules open), Tâches (ordered: ↑ ↓ move a task,
 * an empty row adds one), Pièces (the template's pièces, the dossier's date each arrives on, its errors
 * to spot), Référentiel (the activities aimed at), Réglages (level, behaviour, guided entry), Distributions
 * (given to which, « Donner la mission », and who handed it in, step 2.9b). The answer key is
 * MissionAnswerKeyController's tab.
 */
#[Route('/teacher/library/missions/{id}', requirements: ['id' => '\d+'])]
final class MissionController extends AbstractController
{
    use MissionControllerTrait;

    #[Route('', name: 'app_mission', methods: ['GET', 'POST'])]
    public function general(int $id, Request $request, MissionRepository $missions, MissionSheet $sheet, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $form = ['title' => $mission->getTitle(), 'statement' => $mission->getStatement(), 'modules' => array_map(static fn (MissionModule $module): string => $module->value, $mission->getModules())];

        return $this->tab($mission, $request, 'general', $form, function () use ($request, $mission, $sheet, $me, &$form): void {
            $form = ['title' => PostValue::trimmed($request, 'title'), 'statement' => str_replace("\r\n", "\n", PostValue::trimmed($request, 'statement')), 'modules' => array_values(PostValue::stringsByKey($request, 'modules'))];
            $sheet->describe($mission, $form['title'], $form['statement'], $form['modules'], $me);
        }, ['module_options' => MissionModule::cases()]);
    }

    #[Route('/tasks', name: 'app_mission_tasks', methods: ['GET', 'POST'])]
    public function tasks(int $id, Request $request, MissionRepository $missions, MissionSheet $sheet, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $form = ['tasks' => $mission->getTasks()];

        return $this->tab($mission, $request, 'tasks', $form, function () use ($request, $mission, $sheet, $me, &$form): void {
            $tasks = [];
            foreach (PostValue::rows($request, 'tasks') as $row) {
                $task = ['key' => $row['key'] ?? '', 'title' => $row['title'] ?? '', 'instruction' => str_replace("\r\n", "\n", $row['instruction'] ?? ''), 'check' => $row['check'] ?? MissionTaskCheck::Declared->value];
                if ('' !== $task['title'] || '' !== $task['instruction']) {
                    $tasks[] = $task;
                }
            }
            $move = PostValue::trimmed($request, 'move');
            if (1 === preg_match('/^(\d+):(up|down)$/', $move, $match)) {
                $from = (int) $match[1];
                $to = 'up' === $match[2] ? $from - 1 : $from + 1;
                if (isset($tasks[$from], $tasks[$to])) {
                    [$tasks[$from], $tasks[$to]] = [$tasks[$to], $tasks[$from]];
                }
            }
            $form = ['tasks' => $tasks];
            $sheet->setTasks($mission, $tasks, $me);
        }, ['check_options' => array_map(static fn (MissionTaskCheck $check): array => ['value' => $check->value, 'label' => $check->label()], MissionTaskCheck::cases())]);
    }

    #[Route('/documents', name: 'app_mission_documents', methods: ['GET', 'POST'])]
    public function documents(int $id, Request $request, MissionRepository $missions, DocumentRepository $repository, Documents $documents, PiecePreparation $preparation, DossierContext $context, EntityManagerInterface $entityManager, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $template = $mission->getTemplate();
        $pieces = [];
        $errors = [];
        $counts = [];
        $editable = null !== $template && $this->isGranted(DossierVoter::EDIT, $template);
        if (null !== $template && $this->isGranted(DossierVoter::VIEW, $template)) {
            $context->within($template, function () use ($template, $repository, $preparation, &$pieces, &$counts): void {
                $pieces = array_reverse($repository->findByDossier($template));
                foreach ($pieces as $piece) {
                    $counts[(int) $piece->getId()] = PiecePreparation::isPreparable($piece) ? $preparation->view($piece)->count() : 0;
                }
            });
        }
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST') && null !== $template) {
            $this->refuseBadToken($mission, $request);
            if (!$editable) {
                throw $this->createNotFoundException('The template is not the teacher’s to change.');
            }
            $typed = PostValue::stringsById($request, 'arrival');
            $moved = 0;
            $context->within($template, function () use ($pieces, $typed, $documents, $entityManager, $me, &$errors, &$moved): void {
                $changes = [];
                foreach ($pieces as $piece) {
                    $id = (int) $piece->getId();
                    if (!isset($typed[$id])) {
                        continue;
                    }
                    $day = DateValue::parse($typed[$id]);
                    if (null === $day) {
                        $errors['arrival.'.$id] = 'La date s’écrit jj/mm/aaaa.';
                    } elseif ($day->format('Y-m-d') !== $piece->getReceivedOn()->format('Y-m-d')) {
                        $changes[] = [$piece, $day];
                    }
                }
                if ([] === $errors) {
                    foreach ($changes as [$piece, $day]) {
                        $documents->scheduleArrival($piece, $day, $me);
                        ++$moved;
                    }
                    $entityManager->flush();
                }
            });
            if ([] === $errors) {
                $this->addFlash('status', 0 === $moved ? 'Rien à enregistrer : les dates d’arrivée sont inchangées.' : \sprintf('%d date%s d’arrivée enregistrée%s.', $moved, $moved > 1 ? 's' : '', $moved > 1 ? 's' : ''));

                return $this->redirectToRoute('app_mission_documents', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('mission/documents.html.twig', [
            'mission' => $mission,
            'mission_tab' => 'documents',
            'pieces' => $pieces,
            'error_counts' => $counts,
            'typed' => $request->isMethod('POST') ? PostValue::stringsById($request, 'arrival') : [],
            'errors' => $errors,
            'editable' => $editable,
        ], new Response(status: $status));
    }

    #[Route('/referential', name: 'app_mission_referential', methods: ['GET', 'POST'])]
    public function referential(int $id, Request $request, MissionRepository $missions, MissionSheet $sheet, Referential $referential, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $form = ['activities' => $mission->getActivities()];

        return $this->tab($mission, $request, 'referential', $form, function () use ($request, $mission, $sheet, $me, &$form): void {
            $form = ['activities' => array_values(PostValue::stringsByKey($request, 'activities'))];
            $sheet->aimAt($mission, $form['activities'], $me);
        }, ['processes' => $referential->processes()]);
    }

    #[Route('/settings', name: 'app_mission_settings', methods: ['GET', 'POST'])]
    public function settings(int $id, Request $request, MissionRepository $missions, MissionSheet $sheet, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $form = ['level' => $mission->getAssistanceLevel()->value, 'behaviour' => $mission->getBehaviour()->value, 'guided' => $mission->isGuidedEntryOpen()];

        return $this->tab($mission, $request, 'settings', $form, function () use ($request, $mission, $sheet, $me, &$form): void {
            $form = ['level' => PostValue::trimmed($request, 'level'), 'behaviour' => PostValue::trimmed($request, 'behaviour'), 'guided' => $request->request->getBoolean('guided')];
            $sheet->settle($mission, AssistanceLevel::tryFrom($form['level']), AccountingBehaviour::tryFrom($form['behaviour']), $form['guided'], $me);
        }, [
            'level_options' => array_map(static fn (AssistanceLevel $level): array => ['value' => $level->value, 'label' => $level->label()], AssistanceLevel::cases()),
            'behaviour_options' => array_map(static fn (AccountingBehaviour $behaviour): array => ['value' => $behaviour->value, 'label' => $behaviour->label()], AccountingBehaviour::cases()),
        ]);
    }

    #[Route('/assignments', name: 'app_mission_assignments', methods: ['GET', 'POST'])]
    public function assignments(int $id, Request $request, MissionRepository $missions, MissionAssignmentRepository $assignments, DistributionRepository $distributions, DistributionCopyRepository $copies, MissionProgressRepository $progresses, AnswerKeyStatus $answerKeys, MissionAssignments $giving, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $mission = $this->mission($id, $missions);
        $now = $clock->now();
        $given = $assignments->findByMission($mission);
        $taken = array_map(static fn (MissionAssignment $one): ?int => $one->getDistribution()->getId(), $given);
        $open = array_values(array_filter($distributions->findByAuthor($me), fn (Distribution $one): bool => null !== $mission->getTemplate() && $one->getTemplate() === $mission->getTemplate() && !\in_array($one->getId(), $taken, true) && $this->isGranted(DistributionVoter::MANAGE, $one)));
        $form = ['distribution' => '', 'dueOn' => null, 'dueTime' => '18:00'];
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->refuseBadToken($mission, $request);
            $chosen = PostValue::nullableInt($request, 'distribution');
            $form = ['distribution' => null === $chosen ? '' : (string) $chosen, 'dueOn' => DateValue::parse(PostValue::trimmed($request, 'dueOn')), 'dueTime' => PostValue::trimmed($request, 'dueTime')];
            $distribution = null;
            foreach ($open as $one) {
                if ($one->getId() === $chosen) {
                    $distribution = $one;
                }
            }
            $dueAt = null;
            if (null !== $form['dueOn'] && 1 === preg_match('/^([01]?\d|2[0-3])[:h]([0-5]\d)$/', $form['dueTime'], $match)) {
                $dueAt = $form['dueOn']->setTime((int) $match[1], (int) $match[2]);
            }
            try {
                if (null === $dueAt && null !== $form['dueOn']) {
                    throw MissionRefused::of(['dueTime' => 'L’heure s’écrit hh:mm, par exemple 18:00.']);
                }
                $giving->give($mission, $distribution, $dueAt, $me, $now);
                $this->addFlash('status', \sprintf('Mission donnée : ses réglages valent pour les dossiers de « %s ».', $distribution?->getTitle()));

                return $this->redirectToRoute('app_mission_assignments', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (MissionRefused $refused) {
                $errors = $refused->errors;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('mission/assignments.html.twig', [
            'mission' => $mission,
            'mission_tab' => 'assignments',
            'given' => $given,
            'handed' => $this->handed($given, $copies, $progresses),
            'answer_key_recorded' => $answerKeys->isRecorded($mission),
            'distribution_options' => array_map(static fn (Distribution $one): array => ['value' => (string) $one->getId(), 'label' => $one->getTitle().' · '.$one->getSchoolClass()->getName()], $open),
            'form' => $form,
            'errors' => $errors,
            'now' => $now,
        ], new Response(status: $status));
    }

    /**
     * Who handed the mission in (step 2.9b), per giving: each dossier the distribution made - its
     * student, or its group -, the moment it was handed in, null before.
     *
     * @param list<MissionAssignment> $given
     *
     * @return array<int, list<array{name: string, dossier: int, submittedAt: ?\DateTimeImmutable}>>
     */
    private function handed(array $given, DistributionCopyRepository $copies, MissionProgressRepository $progresses): array
    {
        $submitted = [];
        foreach ($progresses->findByAssignments($given) as $progress) {
            $submitted[(int) $progress->getAssignment()->getId()][(int) $progress->getDossier()->getId()] = $progress->getSubmittedAt();
        }
        $handed = [];
        foreach ($given as $one) {
            $rows = [];
            foreach ($copies->findByDistribution($one->getDistribution()) as $copy) {
                $dossier = $copy->getDossier();
                if (null === $dossier) {
                    continue;
                }
                $rows[] = ['name' => $copy->getStudent()?->getSortName() ?? (string) $copy->getGroupName(), 'dossier' => (int) $dossier->getId(), 'submittedAt' => $submitted[(int) $one->getId()][(int) $dossier->getId()] ?? null];
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $handed[(int) $one->getId()] = $rows;
        }

        return $handed;
    }

    /**
     * A tab with one form: shown, or posted - `$write` reads the form into `$form` and asks the door;
     * a refusal draws the form again with its faults (422), a success goes back to the tab.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $extra
     */
    private function tab(Mission $mission, Request $request, string $tab, array &$form, callable $write, array $extra = []): Response
    {
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->refuseBadToken($mission, $request);
            try {
                $write();
                $this->addFlash('status', 'Mission enregistrée.');

                return $this->redirectToRoute('app_mission'.('general' === $tab ? '' : '_'.$tab), ['id' => $mission->getId()], Response::HTTP_SEE_OTHER);
            } catch (MissionRefused $refused) {
                $errors = $refused->errors;
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }

        return $this->render('mission/'.$tab.'.html.twig', ['mission' => $mission, 'mission_tab' => $tab, 'form' => $form, 'errors' => $errors] + $extra, new Response(status: $status));
    }
}
