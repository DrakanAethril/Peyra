<?php

declare(strict_types=1);

namespace App\Controller\Accounting\Edition;

use App\Entity\Dossier\Dossier;
use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Accounting\Edition\BalanceReport;
use App\Service\Accounting\Edition\EditionExports;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Edition\EditionWindow;
use App\Service\Accounting\Edition\ReportHeader;
use App\Service\Accounting\Edition\ThirdPartyBalanceEdition;
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
 * Comptabilité › Éditions › Balance auxiliaire (lot 1, step 1.5b; gabarit R): the third parties by
 * kind, or one kind (`kind=customer`…), their movements and balances; on screen, as a PDF and as a
 * spreadsheet. A third party and its amounts lead to its consultation.
 */
#[Route('/d/{dossier}/accounting/editions/third-party-balance', requirements: ['dossier' => '\d+'])]
final class ThirdPartyBalanceController extends AbstractController
{
    use EditionControllerTrait;

    #[Route('', name: 'app_accounting_edition_third_party_balance', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, Editions $editions, ThirdPartyBalanceEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return $this->render('accounting/edition/third_party_balance.html.twig', [
            'dossier' => $dossier,
            ...$reading,
            'kinds' => ThirdPartyKind::cases(),
            'keep' => self::keep($reading['window'], $reading['scope'], ['kind' => $reading['kind']?->value]),
            'here' => self::here($request),
        ], new Response(status: null !== $reading['error'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('.pdf', name: 'app_accounting_edition_third_party_balance_pdf', methods: ['GET'])]
    public function pdfView(Dossier $dossier, Request $request, Editions $editions, ThirdPartyBalanceEdition $edition, PdfRenderer $renderer): Response
    {
        return self::pdf($renderer, 'accounting/edition/pdf/third_party_balance.html.twig', ['orientation' => 'landscape', ...$this->read($dossier, $request, $editions, $edition)], 'balance auxiliaire', $dossier);
    }

    #[Route('/export.{format}', name: 'app_accounting_edition_third_party_balance_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, Editions $editions, ThirdPartyBalanceEdition $edition): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition);

        return TableExport::response(EditionExports::thirdPartyBalance($reading['report'], $reading['window']), ExportFormat::from($format), 'balance auxiliaire '.$dossier->getName());
    }

    /** @return array{window: EditionWindow, scope: EntryScope, kind: ?ThirdPartyKind, report: BalanceReport, report_header: ReportHeader, error: ?string} */
    private function read(Dossier $dossier, Request $request, Editions $editions, ThirdPartyBalanceEdition $edition): array
    {
        $window = $editions->window($dossier, QueryValue::trimmed($request, 'from'), QueryValue::trimmed($request, 'to'));
        $scope = self::scope($request);
        $kind = ThirdPartyKind::tryFrom(QueryValue::trimmed($request, 'kind'));

        return [
            'window' => $window,
            'scope' => $scope,
            'kind' => $kind,
            'report' => $edition->read($dossier, $window, $scope, $kind),
            'report_header' => $editions->header($dossier, null === $kind ? 'Balance auxiliaire' : 'Balance auxiliaire · '.mb_strtolower($kind->plural()), $window, $scope),
            'error' => $window->error,
        ];
    }
}
