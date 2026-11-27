<?php

declare(strict_types=1);

namespace App\Controller\Regulatory;

use App\Enum\Regulatory\RegulatoryDomain;
use App\Service\Regulatory\RegulatoryCatalogue;
use App\Service\Regulatory\RegulatoryListing;
use App\Service\Shared\ListPage;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paramètres › Paramètres réglementaires (design-ux.md §§ 2.6 and 3.3): the list of the values of a
 * millésime, filtered by domain and searched, and the sheet of one key with its dated values and
 * their corrections. Every teacher reads them; only administrators write (RegulatoryValueEntryController).
 */
#[Route('/teacher/regulatory')]
final class RegulatoryValuesController extends AbstractController
{
    /** A key as the millésime files write it: « social.smic_hourly ». */
    public const string KEY = '[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+';

    #[Route('', name: 'app_regulatory', methods: ['GET'])]
    public function index(Request $request, RegulatoryCatalogue $catalogue, ClockInterface $clock): Response
    {
        $histories = $catalogue->histories();
        $millesimes = RegulatoryListing::years($histories);
        $thisYear = (int) $clock->now()->format('Y');
        $year = QueryValue::nullableInt($request, 'year');
        if (null === $year || !\in_array($year, $millesimes, true)) {
            // The year of the calendar opens the list: a display choice, read where the request is.
            $year = \in_array($thisYear, $millesimes, true) ? $thisYear : ($millesimes[0] ?? $thisYear);
        }
        $domain = RegulatoryDomain::tryFrom(QueryValue::trimmed($request, 'domain'));
        $search = QueryValue::trimmed($request, 'q');

        $listing = RegulatoryListing::of($histories, $year, $domain, $search);
        $page = ListPage::fromRequest($request, 'regulatory.values')->within($listing->total);

        return $this->render('regulatory/index.html.twig', [
            'value_rows' => \array_slice($listing->rows, $page->offset(), $page->perPage),
            'listing' => $listing,
            'list_page' => $page,
            'counts' => RegulatoryListing::counts($histories, $year, $search),
            'millesimes' => $millesimes,
            'year' => $year,
            'domain' => $domain,
            'domains' => RegulatoryDomain::cases(),
            'search' => $search,
        ]);
    }

    #[Route('/{key}', name: 'app_regulatory_show', requirements: ['key' => self::KEY], methods: ['GET'])]
    public function show(string $key, RegulatoryCatalogue $catalogue, ClockInterface $clock): Response
    {
        $history = $catalogue->history($key) ?? throw new NotFoundHttpException('No such regulatory value.');
        $today = $clock->now();

        return $this->render('regulatory/show.html.twig', [
            'history' => $history,
            'latest' => $history->latest(),
            'in_force' => $history->inForceOn(new \DateTimeImmutable($today->format('Y-m-d'))),
        ]);
    }
}
