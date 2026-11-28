<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Repository\Dossier\ExerciseRepository;
use App\Security\Voter\DossierRightVoter;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Accounting\Opening\OpeningBalance;
use App\Service\Accounting\Template\GuidedEntry;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comptabilité › Saisie › À-nouveaux (lot 1, step 1.6): the opening balance of an exercise - the
 * exercise of the dossier's date by default -, the à-nouveaux journal's entries of its first day by
 * account, and its controls (balanced, the typing left in the grid included; validated; balance
 * sheet accounts only). It is typed in the grid of that journal, the way Sage enters it, which
 * « Saisir les à-nouveaux » opens on the exercise's first month.
 */
#[Route('/d/{dossier}/accounting/opening', requirements: ['dossier' => '\d+'])]
final class OpeningController extends AbstractController
{
    #[Route('', name: 'app_accounting_opening', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, ExerciseRepository $exercises, OpeningBalance $openings, AssistanceLevels $assistance): Response
    {
        $all = $exercises->findByDossier($dossier);
        $code = QueryValue::trimmed($request, 'exercise');
        $exercise = null;
        foreach ($all as $one) {
            if ($one->getCode() === $code) {
                $exercise = $one;
            }
        }
        $exercise ??= $exercises->findAt($dossier, $dossier->getDossierDate()) ?? ($all[0] ?? null);
        $editable = $this->isGranted(DossierVoter::EDIT, $dossier);
        $report = null === $exercise ? null : $openings->of($dossier, $exercise);

        return $this->render('accounting/opening.html.twig', [
            'dossier' => $dossier,
            'exercises' => $all,
            'report' => $report,
            'can_edit' => null !== $report?->journal && $this->isGranted(DossierRightVoter::JOURNAL_ENTER, $report->journal),
            'can_settings' => $this->isGranted(DossierRightVoter::SETTINGS, $dossier),
            'guided_open' => $editable && GuidedEntry::isOpen($assistance->for($dossier), $this->isGranted('ROLE_TEACHER')),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
        ]);
    }
}
