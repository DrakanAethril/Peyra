<?php

declare(strict_types=1);

namespace App\Controller\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Service\Accounting\Edition\AccountRange;
use App\Service\Accounting\Edition\EditionExports;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Edition\EditionWindow;
use App\Service\Accounting\Edition\GeneralLedgerEdition;
use App\Service\Accounting\Edition\GeneralLedgerReport;
use App\Service\Accounting\Edition\ReportHeader;
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
 * Comptabilité › Éditions › Grand livre (lot 1, step 1.5b; gabarit R): every account of the
 * selection, what it carried the eve, its movements with the running balance, its total; on
 * screen, as a PDF and as a spreadsheet. An account and its amounts lead to its consultation.
 */
#[Route('/d/{dossier}/accounting/editions/general-ledger', requirements: ['dossier' => '\d+'])]
final class GeneralLedgerController extends AbstractController
{
    use EditionControllerTrait;

    #[Route('', name: 'app_accounting_edition_general_ledger', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, Editions $editions, GeneralLedgerEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return $this->render('accounting/edition/general_ledger.html.twig', [
            'dossier' => $dossier,
            ...$reading,
            'keep' => self::keep($reading['window'], $reading['scope'], ['account_from' => $reading['range']->from ?: null, 'account_to' => $reading['range']->to ?: null]),
            'here' => self::here($request),
        ], new Response(status: null !== $reading['error'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('.pdf', name: 'app_accounting_edition_general_ledger_pdf', methods: ['GET'])]
    public function pdfView(Dossier $dossier, Request $request, Editions $editions, GeneralLedgerEdition $edition, PdfRenderer $renderer): Response
    {
        return self::pdf($renderer, 'accounting/edition/pdf/general_ledger.html.twig', ['orientation' => 'landscape', ...$this->read($dossier, $request, $editions, $edition)], 'grand livre', $dossier);
    }

    #[Route('/export.{format}', name: 'app_accounting_edition_general_ledger_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, Editions $editions, GeneralLedgerEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return TableExport::response(EditionExports::generalLedger($reading['report'], $reading['window']), ExportFormat::from($format), 'grand livre '.$dossier->getName());
    }

    /** @return array{window: EditionWindow, scope: EntryScope, range: AccountRange, report: GeneralLedgerReport, report_header: ReportHeader, error: ?string} */
    private function read(Dossier $dossier, Request $request, Editions $editions, GeneralLedgerEdition $edition): array
    {
        $window = $editions->window($dossier, QueryValue::trimmed($request, 'from'), QueryValue::trimmed($request, 'to'));
        $scope = self::scope($request);
        $range = AccountRange::read(QueryValue::trimmed($request, 'account_from'), QueryValue::trimmed($request, 'account_to'));

        return [
            'window' => $window,
            'scope' => $scope,
            'range' => $range,
            'report' => $edition->read($dossier, $window, $scope, $range),
            'report_header' => $editions->header($dossier, 'Grand livre', $window, $scope, [$range->describe()]),
            'error' => implode(' ', array_filter([$window->error, $range->error])) ?: null,
        ];
    }
}
