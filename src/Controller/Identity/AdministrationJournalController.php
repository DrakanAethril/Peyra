<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Repository\Identity\AdministrationEventRepository;
use App\Service\Shared\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Paramètres › Administration › Journal d'administration: every gesture of these screens, newest first. */
final class AdministrationJournalController extends AbstractController
{
    public const int PER_PAGE = 50;

    #[Route('/teacher/admin/journal', name: 'app_admin_journal', methods: ['GET'])]
    public function index(Request $request, AdministrationEventRepository $events): Response
    {
        $total = $events->countAll();
        $pages = max(1, intdiv($total + self::PER_PAGE - 1, self::PER_PAGE));
        $page = min(max(1, QueryValue::int($request, 'page', 1)), $pages);

        return $this->render('admin/journal.html.twig', [
            'events' => $events->findPage($page, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
    }
}
