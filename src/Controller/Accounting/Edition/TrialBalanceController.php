<?php

declare(strict_types=1);

namespace App\Controller\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Edition\AccountRange;
use App\Service\Accounting\Edition\BalanceReport;
use App\Service\Accounting\Edition\EditionExports;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Edition\EditionWindow;
use App\Service\Accounting\Edition\ReportHeader;
use App\Service\Accounting\Edition\TrialBalanceEdition;
use App\Service\Accounting\Ledger\EntryScope;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\Pdf\PdfRenderer;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comptabilité › Éditions › Balance (« balance générale », lot 1, step 1.5b; gabarit R): the
 * accounts of the selection by class, the eve's balance when the window starts after the
 * exercise, the movements, the balance at the end in a debit and a credit column; on screen, as a
 * PDF and as a spreadsheet. An account and its amounts lead to its consultation.
 */
#[Route('/d/{dossier}/accounting/editions/trial-balance', requirements: ['dossier' => '\d+'])]
final class TrialBalanceController extends AbstractController
{
    use EditionControllerTrait;

    #[Route('', name: 'app_accounting_edition_trial_balance', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, Editions $editions, TrialBalanceEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return $this->render('accounting/edition/trial_balance.html.twig', [
            'dossier' => $dossier,
            ...$reading,
            'keep' => self::keep($reading['window'], $reading['scope'], ['account_from' => $reading['range']->from ?: null, 'account_to' => $reading['range']->to ?: null]),
            'here' => self::here($request),
        ], new Response(status: null !== $reading['error'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('.pdf', name: 'app_accounting_edition_trial_balance_pdf', methods: ['GET'])]
    public function pdfView(Dossier $dossier, Request $request, Editions $editions, TrialBalanceEdition $edition, PdfRenderer $renderer): Response
    {
        return self::pdf($renderer, 'accounting/edition/pdf/trial_balance.html.twig', ['orientation' => 'landscape', ...$this->read($dossier, $request, $editions, $edition)], 'balance', $dossier);
    }

    #[Route('/export.{format}', name: 'app_accounting_edition_trial_balance_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, Editions $editions, TrialBalanceEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return TableExport::response(EditionExports::trialBalance($reading['report'], $reading['window']), ExportFormat::from($format), 'balance '.$dossier->getName());
    }

    /** @return array{window: EditionWindow, scope: EntryScope, range: AccountRange, report: BalanceReport, report_header: ReportHeader, error: ?string} */
    private function read(Dossier $dossier, Request $request, Editions $editions, TrialBalanceEdition $edition): array
    {
        $window = $editions->window($dossier, QueryValue::trimmed($request, 'from'), QueryValue::trimmed($request, 'to'));
        $scope = self::scope($request);
        $range = AccountRange::read(QueryValue::trimmed($request, 'account_from'), QueryValue::trimmed($request, 'account_to'));

        return [
            'window' => $window,
            'scope' => $scope,
            'range' => $range,
            'report' => $edition->read($dossier, $window, $scope, $range),
            'report_header' => $editions->header($dossier, 'Balance générale', $window, $scope, [$range->describe()]),
            'error' => implode(' ', array_filter([$window->error, $range->error])) ?: null,
        ];
    }
}
