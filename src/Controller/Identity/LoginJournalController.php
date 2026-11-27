<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Repository\Identity\LoginEventRepository;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paramètres › Administration › Journal des connexions: every sign-in, successful or refused,
 * newest first, filtered by outcome and searched by login or name. Administrators only.
 */
final class LoginJournalController extends AbstractController
{
    public const int PER_PAGE = 50;

    /** The filter chips: query value => null for every line, true for refusals, false for successes. */
    private const array FILTERS = ['' => null, 'refused' => true, 'succeeded' => false];

    #[Route('/teacher/admin/logins', name: 'app_admin_logins', methods: ['GET'])]
    public function index(Request $request, LoginEventRepository $events): Response
    {
        $filter = QueryValue::trimmed($request, 'outcome');
        if (!\array_key_exists($filter, self::FILTERS)) {
            $filter = '';
        }
        $search = QueryValue::trimmed($request, 'q');
        $refused = self::FILTERS[$filter];
        $total = $events->countFiltered($refused, $search);
        $pages = max(1, intdiv($total + self::PER_PAGE - 1, self::PER_PAGE));
        $page = min(max(1, QueryValue::int($request, 'page', 1)), $pages);

        return $this->render('admin/logins.html.twig', [
            'events' => $events->findPage($refused, $search, $page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'filter' => $filter,
            'search' => $search,
            'counts' => [
                '' => $events->countFiltered(null, $search),
                'refused' => $events->countFiltered(true, $search),
                'succeeded' => $events->countFiltered(false, $search),
            ],
        ]);
    }
}
