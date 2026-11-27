<?php

declare(strict_types=1);

namespace App\Controller\Regulatory;

use App\Entity\Identity\User;
use App\Service\Regulatory\RegulatoryCatalogue;
use App\Service\Regulatory\RegulatoryDraft;
use App\Service\Regulatory\RegulatoryValues;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Nouvelle valeur datée » (design-ux.md § 3.3), administrators only - anybody else gets the 404 of
 * a page that is not there. The form starts from the key's latest value: a person changes its
 * leaves and gives the date it applies from; a date already held corrects that date's value, the
 * corrected one staying in the history (App\Service\Regulatory\RegulatoryValues).
 */
#[IsGranted('ROLE_ADMIN')]
final class RegulatoryValueEntryController extends AbstractController
{
    #[Route('/teacher/regulatory/{key}/new', name: 'app_regulatory_new', requirements: ['key' => RegulatoryValuesController::KEY], methods: ['GET', 'POST'])]
    public function new(string $key, Request $request, RegulatoryCatalogue $catalogue, RegulatoryValues $values, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        $history = $catalogue->history($key) ?? throw new NotFoundHttpException('No such regulatory value.');
        $draft = RegulatoryDraft::startingFrom($history->latest());
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('regulatory-new-'.$key, $request->request->getString('_token'))) {
                throw new NotFoundHttpException('Invalid CSRF token.');
            }
            $draft->effectiveFrom = DateValue::parse(PostValue::trimmed($request, 'effective_from'));
            $typed = [];
            foreach (PostValue::stringsByKey($request, 'leaf') as $index => $text) {
                if (1 === preg_match('/^\d+$/', (string) $index)) {
                    $typed[(int) $index] = $text;
                }
            }
            $draft->leaves = array_map(static fn (int $index): string => $typed[$index] ?? '', array_keys($draft->baseLeaves()));
            $draft->sourceUrl = PostValue::trimmed($request, 'source_url');
            $consulted = PostValue::trimmed($request, 'consulted_on');
            $draft->consultedOn = DateValue::parse($consulted);
            $draft->consultedOnUnreadable = '' !== $consulted && null === $draft->consultedOn;
            $draft->verified = '1' === PostValue::trimmed($request, 'verified');
            $draft->note = PostValue::trimmed($request, 'note');

            $value = $values->add($history, $draft, $me, $clock->now());
            if (null !== $value) {
                $this->addFlash('status', \sprintf('Valeur du %s enregistrée.', $value->getEffectiveFrom()->format('d/m/Y')));

                return $this->redirectToRoute('app_regulatory_show', ['key' => $key], Response::HTTP_SEE_OTHER);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('regulatory/new.html.twig', [
            'history' => $history,
            'draft' => $draft,
            'today' => $clock->now(),
        ], new Response(status: $status));
    }
}
