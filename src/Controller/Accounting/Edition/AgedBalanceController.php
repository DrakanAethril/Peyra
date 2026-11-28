<?php

declare(strict_types=1);

namespace App\Controller\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\AgeBand;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Edition\AgedBalanceEdition;
use App\Service\Accounting\Edition\AgedBalanceReport;
use App\Service\Accounting\Edition\EditionExports;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Edition\EditionWindow;
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
 * Comptabilité › Éditions › Balance âgée (lot 1, step 1.5b, prepared for lot 2; gabarit R): at a
 * day - the dossier's date by default -, what the customers (`kind=customer`, by default) or the
 * suppliers owe or are owed, by age band; on screen, as a PDF and as a spreadsheet. A third party
 * and its amounts lead to its consultation.
 */
#[Route('/d/{dossier}/accounting/editions/aged-balance', requirements: ['dossier' => '\d+'])]
final class AgedBalanceController extends AbstractController
{
    use EditionControllerTrait;

    /** The kinds an aged balance is read for: what is owed by customers, what is owed to suppliers. */
    private const array KINDS = [ThirdPartyKind::Customer, ThirdPartyKind::Supplier];

    #[Route('', name: 'app_accounting_edition_aged_balance', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, Editions $editions, AgedBalanceEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return $this->render('accounting/edition/aged_balance.html.twig', [
            'dossier' => $dossier,
            ...$reading,
            'kinds' => self::KINDS,
            'keep' => [
                'at' => $reading['window']->to->format('Y-m-d'),
                'scope' => EntryScope::Validated === $reading['scope'] ? 'validated' : null,
                'kind' => ThirdPartyKind::Customer === $reading['kind'] ? null : $reading['kind']->value,
            ],
            'here' => self::here($request),
        ], new Response(status: null !== $reading['error'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('.pdf', name: 'app_accounting_edition_aged_balance_pdf', methods: ['GET'])]
    public function pdfView(Dossier $dossier, Request $request, Editions $editions, AgedBalanceEdition $edition, PdfRenderer $renderer): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return self::pdf($renderer, 'accounting/edition/pdf/aged_balance.html.twig', ['orientation' => 'landscape', ...$reading], 'balance agee '.$reading['kind']->plural(), $dossier);
    }

    #[Route('/export.{format}', name: 'app_accounting_edition_aged_balance_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, Editions $editions, AgedBalanceEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return TableExport::response(EditionExports::agedBalance($reading['report']), ExportFormat::from($format), 'balance agee '.$reading['kind']->plural().' '.$dossier->getName());
    }

    /** @return array{window: EditionWindow, scope: EntryScope, kind: ThirdPartyKind, bands: list<AgeBand>, report: AgedBalanceReport, report_header: ReportHeader, error: ?string} */
    private function read(Dossier $dossier, Request $request, Editions $editions, AgedBalanceEdition $edition): array
    {
        $window = $editions->windowAt($dossier, QueryValue::trimmed($request, 'at'));
        $scope = self::scope($request);
        $kind = ThirdPartyKind::Supplier->value === QueryValue::trimmed($request, 'kind') ? ThirdPartyKind::Supplier : ThirdPartyKind::Customer;
        $header = $editions->header($dossier, 'Balance âgée · '.mb_strtolower($kind->plural()), $window, $scope);

        return [
            'window' => $window,
            'scope' => $scope,
            'kind' => $kind,
            'bands' => AgeBand::cases(),
            'report' => $edition->read($dossier, $window, $scope, $kind),
            'report_header' => new ReportHeader($header->company, $header->title, $header->fiscalYear, \sprintf('Au %s · %s', $window->to->format('d/m/Y'), Editions::scope($scope)), $header->dossierDate, $header->student),
            'error' => $window->error,
        ];
    }
}
