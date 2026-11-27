<?php

declare(strict_types=1);

namespace App\Controller\Identity;

use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Identity\ClassBoard;
use App\Service\Identity\SchoolYears;
use App\Service\Identity\YearPreparation;
use App\Service\Shared\PostValue;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Paramètres › Administration › Années scolaires (design-ux.md § 2.6): the years, « Nouvelle
 * année » (taking over the previous year's classes and mapping, ticked by default), « Rendre
 * courante ». Administrators only (access_control, ^/teacher/admin).
 */
#[Route('/teacher/admin/years')]
final class SchoolYearsController extends AbstractController
{
    #[Route('', name: 'app_admin_years', methods: ['GET'])]
    public function index(SchoolYearRepository $years, SchoolYears $schoolYears, ClassBoard $board, YearPreparation $preparation, ClockInterface $clock): Response
    {
        [$start, $end] = $schoolYears->proposedDates($clock->now());

        return $this->page($years, $board, $preparation, $start->format('Y-m-d'), $end->format('Y-m-d'), true);
    }

    #[Route('', name: 'app_admin_years_create', methods: ['POST'])]
    public function create(Request $request, SchoolYearRepository $years, SchoolYears $schoolYears, ClassBoard $board, YearPreparation $preparation, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$this->isCsrfTokenValid('admin-years-create', $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
        $startText = PostValue::trimmed($request, 'starts_on');
        $endText = PostValue::trimmed($request, 'ends_on');
        $takeOver = '1' === PostValue::trimmed($request, 'take_over');
        $result = $schoolYears->create(self::date($startText), self::date($endText), $takeOver, $me, $clock->now());
        if (\is_string($result)) {
            return $this->page($years, $board, $preparation, $startText, $endText, $takeOver, $result, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('status', \sprintf('Année %s créée%s.', $result->getLabel(), $result->isCurrent() ? ' ; c’est l’année courante' : ''));

        return $this->redirectToRoute('app_admin_years', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/current', name: 'app_admin_years_current', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function makeCurrent(#[MapEntity] SchoolYear $year, Request $request, SchoolYears $schoolYears, ClockInterface $clock, #[CurrentUser] User $me): Response
    {
        if (!$this->isCsrfTokenValid('admin-years-current-'.$year->getId(), $request->request->getString('_token'))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
        $schoolYears->makeCurrent($year, $me, $clock->now());
        $this->addFlash('status', \sprintf('%s est l’année courante.', $year->getLabel()));

        return $this->redirectToRoute('app_admin_years', status: Response::HTTP_SEE_OTHER);
    }

    private function page(SchoolYearRepository $years, ClassBoard $board, YearPreparation $preparation, string $start, string $end, bool $takeOver, ?string $error = null, int $status = Response::HTTP_OK): Response
    {
        $all = $years->findAllNewestFirst();
        $current = $years->findCurrent();
        $rows = [];
        foreach ($all as $year) {
            $classes = $board->rows($year);
            $rows[] = [
                'year' => $year,
                'state' => $year->stateAgainst($current),
                'classes' => \count($classes),
                'students' => array_sum(array_map(static fn ($r): int => $r->students, $classes)),
            ];
        }

        return $this->render('admin/years.html.twig', [
            'year_rows' => $rows,
            'latest' => $all[0] ?? null,
            'starts_on' => $start,
            'ends_on' => $end,
            'take_over' => $takeOver,
            'error' => $error,
            'checks' => $preparation->checks(),
        ], new Response(status: $status));
    }

    /** A `YYYY-MM-DD` field (an `<input type="date">`), null when it is not one. */
    private static function date(string $text): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return false === $date || $date->format('Y-m-d') !== $text ? null : $date;
    }
}
