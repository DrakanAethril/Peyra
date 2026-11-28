<?php

declare(strict_types=1);

namespace App\Controller\Library;

use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRepository;
use App\Security\Voter\TemplateVoter;
use App\Service\Accounting\Chart\ReferenceChart;
use App\Service\Accounting\Chart\ReferenceCharts;
use App\Service\Dossier\Library\EmptyTemplateDraft;
use App\Service\Dossier\Library\TemplateCard;
use App\Service\Dossier\Library\TemplateDuplicator;
use App\Service\Dossier\Library\TemplateFactory;
use App\Service\Dossier\Library\TemplateLibrary;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Bibliothèque › Nouveau dossier modèle (design-ux.md § 3.3, gabarit E): first the start - an
 * empty dossier, or a copy of a template the teacher reads -, then, for an empty one, the company,
 * its first exercise and its plan. « Depuis un FEC » comes with the FEC (step 1.9).
 */
final class NewTemplateController extends AbstractController
{
    #[Route('/teacher/library/new', name: 'app_library_new', methods: ['GET', 'POST'])]
    public function start(Request $request, TemplateLibrary $library, DossierRepository $dossiers, TemplateDuplicator $duplicator, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $readable = $library->cards($me);
        $error = null;
        $title = '';
        $chosen = '';
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('library-new-copy', $request->request->getString('_token'))) {
                throw new NotFoundHttpException('A stale form.');
            }
            $chosen = PostValue::trimmed($request, 'template');
            $title = PostValue::trimmed($request, 'title');
            $template = ctype_digit($chosen) ? $dossiers->find((int) $chosen) : null;
            if (null === $template || !$this->isGranted(TemplateVoter::DUPLICATE, $template)) {
                $error = 'Choisissez le modèle à copier dans la liste.';
            } else {
                $copy = $duplicator->duplicate($template, $me, $clock->now(), $title);
                $this->addFlash('status', \sprintf('Modèle « %s » créé, copie de « %s ».', $copy->getLibraryTitle(), $template->getLibraryTitle()));

                return $this->redirectToRoute('app_library_template', ['dossier' => $copy->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('library/new.html.twig', [
            'templates' => array_map(static fn (TemplateCard $card): array => ['value' => (string) $card->template->getId(), 'label' => $card->template->getLibraryTitle().' · '.$card->shelf->label()], $readable),
            'error' => $error,
            'copy_title' => $title,
            'chosen' => $chosen,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/teacher/library/new/empty', name: 'app_library_new_empty', methods: ['GET', 'POST'])]
    public function empty(Request $request, TemplateFactory $factory, ReferenceCharts $charts, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $now = $clock->now();
        $draft = new EmptyTemplateDraft();
        $year = (int) $now->format('Y');
        $draft->exerciseStart = $year.'-01-01';
        $draft->exerciseEnd = $year.'-12-31';
        $draft->dossierDate = $year.'-01-01';
        $draft->chart = $charts->forExerciseStartingOn(new \DateTimeImmutable($draft->exerciseStart))->code;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('library-new-empty', $request->request->getString('_token'))) {
                throw new NotFoundHttpException('A stale form.');
            }
            $draft = EmptyTemplateDraft::fromRequest($request);
            $template = $factory->create($draft, $me, $now);
            if (null !== $template) {
                $this->addFlash('status', \sprintf('Modèle « %s » créé : son plan, son exercice et ses cinq journaux sont prêts.', $template->getLibraryTitle()));

                return $this->redirectToRoute('app_library_template', ['dossier' => $template->getId()], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('library/new_empty.html.twig', [
            'form' => $draft,
            'draft' => $draft->company,
            'dates' => [
                'exerciseStart' => DateValue::parse($draft->exerciseStart),
                'exerciseEnd' => DateValue::parse($draft->exerciseEnd),
                'dossierDate' => DateValue::parse($draft->dossierDate),
            ],
            'reference' => DateValue::parse($draft->exerciseStart) ?? $now->setTime(0, 0),
            'charts' => array_map(static fn (ReferenceChart $chart): array => [
                'value' => $chart->code,
                'label' => $chart->label.(null === $chart->appliesFrom ? ' · exercices ouverts avant 2025' : ' · exercices ouverts depuis le '.$chart->appliesFrom->format('d/m/Y')),
            ], $charts->all()),
        ], new Response(status: $status));
    }
}
