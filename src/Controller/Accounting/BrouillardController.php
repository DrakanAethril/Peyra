<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Accounting\EntryRepository;
use App\Repository\Accounting\PendingEntryRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Accounting\Consultation\Brouillard;
use App\Service\Accounting\Consultation\BrouillardRow;
use App\Service\Accounting\Consultation\EntryExport;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\Money;
use App\Service\Shared\PostValue;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comptabilité › Brouillard (lot 1, step 1.5; design-ux.md § 3.2, gabarit L): the entries not yet
 * validated, filtered by journal and month (chips that count them), each with its controls at the
 * dossier's level of assistance; the boxes of the selection, « Valider la sélection » (Ctrl Entrée)
 * after the confirmation bar - all or nothing, through the Ledger, a draft a control stops at G and
 * B held back. The pending work, which is not in the brouillard, is named above with its grid.
 */
#[Route('/d/{dossier}/accounting/brouillard', requirements: ['dossier' => '\d+'])]
final class BrouillardController extends AbstractController
{
    #[Route('', name: 'app_accounting_brouillard', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, Brouillard $brouillard, AssistanceLevels $assistance, PendingEntryRepository $pending, #[CurrentUser] User $me): Response
    {
        $all = $brouillard->rows($dossier, null, null, $me);
        [$journal, $period] = [mb_strtoupper(QueryValue::trimmed($request, 'journal')), QueryValue::trimmed($request, 'period')];
        $journalCounts = [];
        $periodCounts = [];
        foreach ($all as $row) {
            $code = $row->entry->getJournal()->getCode();
            $journalCounts[$code] = ['label' => $row->entry->getJournal()->getLabel(), 'count' => ($journalCounts[$code]['count'] ?? 0) + 1];
            if ('' === $journal || $journal === $code) {
                $month = $row->entry->getPeriod()->getStartsOn()->format('Y-m-d');
                $periodCounts[$month] = ['label' => $row->entry->getPeriod()->getLabel(), 'count' => ($periodCounts[$month]['count'] ?? 0) + 1];
            }
        }
        ksort($journalCounts);
        ksort($periodCounts);
        $shown = array_values(array_filter($all, static fn (BrouillardRow $row): bool => ('' === $journal || $row->entry->getJournal()->getCode() === $journal)
            && ('' === $period || $row->entry->getPeriod()->getStartsOn()->format('Y-m-d') === $period)));

        return $this->render('accounting/brouillard.html.twig', [
            'dossier' => $dossier,
            'brouillard_rows' => $shown,
            'total_count' => \count($all),
            'journal_counts' => $journalCounts,
            'period_counts' => $periodCounts,
            'journal' => $journal,
            'period' => $period,
            'amount' => Money::sum(array_map(static fn (BrouillardRow $row): Money => $row->entry->totalDebit(), $shown)),
            'level' => $assistance->for($dossier),
            'pending' => array_values(array_filter($pending->findAllPending(), static fn ($one): bool => [] !== $one->getGroups())),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
        ]);
    }

    #[Route('/validate', name: 'app_accounting_brouillard_validate', methods: ['POST'])]
    public function validate(Dossier $dossier, Request $request, Brouillard $brouillard, #[CurrentUser] User $me): Response
    {
        if (!$this->isGranted(DossierVoter::EDIT, $dossier) || !$this->isCsrfTokenValid('brouillard-'.$dossier->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Not yours to validate.');
        }
        $ids = PostValue::intList($request, 'entries');
        if ([] === $ids) {
            $this->addFlash('status', 'Cochez les écritures à valider.');
        } else {
            $this->addFlash('status', $brouillard->validate($dossier, $ids, $me)->message());
        }

        return $this->redirectToRoute('app_accounting_brouillard', array_filter([
            'dossier' => $dossier->getId(),
            'journal' => PostValue::trimmed($request, 'journal'),
            'period' => PostValue::trimmed($request, 'period'),
        ], static fn (int|string|null $value): bool => null !== $value && '' !== $value), Response::HTTP_SEE_OTHER);
    }

    #[Route('/export.{format}', name: 'app_accounting_brouillard_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, string $format, EntryRepository $entries): Response
    {
        return TableExport::response(EntryExport::table('Brouillard', $entries->findDrafts()), ExportFormat::from($format), 'brouillard-'.$dossier->getName());
    }
}
