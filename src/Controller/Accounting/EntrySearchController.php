<?php

declare(strict_types=1);

namespace App\Controller\Accounting;

use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Repository\Accounting\JournalRepository;
use App\Repository\Dossier\PeriodRepository;
use App\Service\Accounting\Consultation\AccountStatements;
use App\Service\Accounting\Consultation\ConsultationWindow;
use App\Service\Accounting\Consultation\EntryExport;
use App\Service\Accounting\Consultation\EntrySearch;
use App\Service\Accounting\Consultation\EntrySearchCriteria;
use App\Service\Shared\DateValue;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use App\Service\Shared\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Comptabilité › Consultation › Recherche d'écritures (lot 1, step 1.5; design-ux.md § 3.2,
 * gabarit L): the dossier's entries filtered by journal, window, account or third party, exact
 * amount, text (document or label) and state - newest first. Narrowed to a journal and a month it is the
 * journal's interrogation (the grid's head links to it). An entry opens its fiche, which leads
 * back here. Count and amount over the whole filter; its export.
 */
#[Route('/d/{dossier}/accounting/entries', requirements: ['dossier' => '\d+'])]
final class EntrySearchController extends AbstractController
{
    private const array FILTERS = ['journal', 'period', 'account', 'amount', 'q', 'state', 'sort', 'dir', 'from', 'to'];

    #[Route('', name: 'app_accounting_entries', methods: ['GET'])]
    public function index(Dossier $dossier, Request $request, EntrySearch $search, JournalRepository $journals, PeriodRepository $periods, AccountStatements $statements): Response
    {
        $criteria = $this->criteria($dossier, $request, $journals, $periods, $statements);
        $page = ListPage::fromRequest($request, 'accounting.entries');
        $result = $search->page($criteria, $page);

        return $this->render('accounting/entries.html.twig', [
            'dossier' => $dossier,
            'criteria' => $criteria,
            'result' => $result,
            'state_counts' => $search->countByState($criteria),
            'list_page' => $page->within($result->total),
            'journals' => $journals->findByDossier($dossier),
            'periods' => $periods->findByDossier($dossier),
            'default_from' => $statements->defaultFrom($dossier),
            'here' => ReturnPath::here($request->getPathInfo(), (string) $request->getQueryString()),
            'filters' => $this->filters($request),
        ], new Response(status: [] !== $criteria->errors ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/export.{format}', name: 'app_accounting_entries_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Dossier $dossier, Request $request, string $format, EntrySearch $search, JournalRepository $journals, PeriodRepository $periods, AccountStatements $statements): Response
    {
        $criteria = $this->criteria($dossier, $request, $journals, $periods, $statements);
        $table = EntryExport::table('Écritures', $search->all($criteria));

        return TableExport::response($table, ExportFormat::from($format), 'ecritures-'.$dossier->getName());
    }

    /**
     * The window is a month of the dossier (`period`, its first day), else `from` and `to`, else the
     * exercise up to the dossier's date.
     */
    private function criteria(Dossier $dossier, Request $request, JournalRepository $journals, PeriodRepository $periods, AccountStatements $statements): EntrySearchCriteria
    {
        $window = ConsultationWindow::read(QueryValue::trimmed($request, 'from'), QueryValue::trimmed($request, 'to'), $statements->defaultFrom($dossier), $dossier->getDossierDate());
        $month = DateValue::parse(QueryValue::trimmed($request, 'period'));
        $period = null === $month ? null : $periods->findAt($dossier, $month);
        if (null !== $period && $period->getStartsOn() == $month) {
            $window = ConsultationWindow::of($period->getStartsOn(), $period->getEndsOn());
        }

        return EntrySearchCriteria::read(
            $this->filters($request),
            $window,
            static fn (string $code): ?Journal => $journals->findOneByCode($dossier, $code),
            static fn (string $typed) => $statements->subject($dossier, $typed),
        );
    }

    /** @return array<string, string> the filters as typed, empty ones left out */
    private function filters(Request $request): array
    {
        $filters = [];
        foreach (self::FILTERS as $name) {
            $value = QueryValue::trimmed($request, $name);
            if ('' !== $value) {
                $filters[$name] = $value;
            }
        }

        return $filters;
    }
}
