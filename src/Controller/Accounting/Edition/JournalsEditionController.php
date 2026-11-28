<?php

declare(strict_types=1);

namespace App\Controller\Accounting\Edition;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Repository\Accounting\JournalRepository;
use App\Service\Accounting\Edition\EditionExports;
use App\Service\Accounting\Edition\Editions;
use App\Service\Accounting\Edition\EditionWindow;
use App\Service\Accounting\Edition\JournalEdition;
use App\Service\Accounting\Edition\JournalReport;
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
 * Comptabilité › Éditions › Journaux (lot 1, step 1.5b; design-ux.md § 3.1, gabarit R): the
 * entries of the window journal by journal, month by month, every line; on screen, as a PDF and
 * as a spreadsheet. A line's amount leads to its account's consultation, its number to the entry.
 */
#[Route('/d/{dossier}/accounting/editions/journals', requirements: ['dossier' => '\d+'])]
final class JournalsEditionController extends AbstractController
{
    use EditionControllerTrait;

    #[Route('', name: 'app_accounting_edition_journals', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, Editions $editions, JournalEdition $edition, JournalRepository $journals): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition, $journals);

        return $this->render('accounting/edition/journals.html.twig', [
            'dossier' => $dossier,
            ...$reading,
            'journals' => $journals->findByDossier($dossier),
            'keep' => self::keep($reading['window'], $reading['scope'], ['journal' => $reading['journal']?->getCode()]),
            'here' => self::here($request),
        ], new Response(status: null !== $reading['error'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('.pdf', name: 'app_accounting_edition_journals_pdf', methods: ['GET'])]
    public function pdfView(Dossier $dossier, Request $request, Editions $editions, JournalEdition $edition, JournalRepository $journals, PdfRenderer $renderer): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition, $journals);

        return self::pdf($renderer, 'accounting/edition/pdf/journals.html.twig', ['orientation' => 'landscape', ...$reading], null === $reading['journal'] ? 'journaux' : 'journal '.$reading['journal']->getCode(), $dossier);
    }

    #[Route('/export.{format}', name: 'app_accounting_edition_journals_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, Editions $editions, JournalEdition $edition, JournalRepository $journals): Response
    {
        $reading = $this->read($dossier, $request, $editions, $edition, $journals);

        return TableExport::response(EditionExports::journals($reading['report']), ExportFormat::from($format), 'journaux '.$dossier->getName());
    }

    /** @return array{window: EditionWindow, scope: EntryScope, journal: ?Journal, report: JournalReport, report_header: ReportHeader, error: ?string} */
    private function read(Dossier $dossier, Request $request, Editions $editions, JournalEdition $edition, JournalRepository $journals): array
    {
        $window = $editions->window($dossier, QueryValue::trimmed($request, 'from'), QueryValue::trimmed($request, 'to'));
        $scope = self::scope($request);
        $code = mb_strtoupper(QueryValue::trimmed($request, 'journal'));
        $journal = '' === $code ? null : $journals->findOneByCode($dossier, $code);
        $unknown = '' !== $code && null === $journal ? \sprintf('Le dossier n’a pas de journal %s : tous les journaux sont édités.', $code) : null;
        $error = implode(' ', array_filter([$window->error, $unknown])) ?: null;

        return [
            'window' => $window,
            'scope' => $scope,
            'journal' => $journal,
            'report' => $edition->read($dossier, $window, $scope, $journal),
            'report_header' => $editions->header($dossier, null === $journal ? 'Journaux' : 'Journal '.$journal->getCode().' · '.$journal->getLabel(), $window, $scope),
            'error' => $error,
        ];
    }
}
