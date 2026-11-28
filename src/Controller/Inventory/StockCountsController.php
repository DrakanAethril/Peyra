<?php

declare(strict_types=1);

namespace App\Controller\Inventory;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\StockCount;
use App\Entity\Inventory\StockMovement;
use App\Repository\Inventory\StockCountRepository;
use App\Security\Voter\DossierRightVoter;
use App\Service\Accounting\Entry\AssistanceLevels;
use App\Service\Inventory\Stock\StockCountRefused;
use App\Service\Inventory\Stock\StockCountRules;
use App\Service\Inventory\Stock\StockCounts;
use App\Service\Inventory\Stock\StockReader;
use App\Service\Shared\Assistance\Assistance;
use App\Service\Shared\DateValue;
use App\Service\Shared\PostValue;
use Brick\Math\BigDecimal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Achats et stocks › Stocks › Inventaire physique (step 2.5b; design-ux.md § 3.2, gabarit G): the
 * dossier's inventories, the latest first; « Nouvel inventaire » opens one for a storage place at a
 * day; its sheet shows each followed article's theoretical quantity, the quantity counted, the gap
 * and its reason - F10 saves the counts, Ctrl Entrée validates after the confirmation bar (each gap
 * an adjustment), an inventory in progress is deleted in two steps. Read under PURCHASES_READ,
 * counted under PURCHASES_ENTER, validated under PURCHASES_VALIDATE.
 */
#[Route('/d/{dossier}/inventory/stock/counts', requirements: ['dossier' => '\d+'])]
final class StockCountsController extends AbstractController
{
    #[Route('', name: 'app_inventory_stock_counts', methods: ['GET'])]
    public function index(Dossier $dossier, StockCountRepository $counts): Response
    {
        $this->denyUnless(DossierRightVoter::PURCHASES_READ, $dossier);
        $rows = array_map(static function (StockCount $count): array {
            $lines = $count->getLines();

            return ['count' => $count, 'lines' => \count($lines), 'counted' => \count(array_filter($lines, static fn ($line): bool => null !== $line->counted))];
        }, $counts->findLatestFirst($dossier));

        return $this->render('inventory/stock/counts.html.twig', ['dossier' => $dossier, 'count_rows' => $rows]);
    }

    #[Route('/new', name: 'app_inventory_stock_count_new', methods: ['GET', 'POST'])]
    public function create(Dossier $dossier, Request $request, StockCounts $stockCounts, StockReader $reader, #[CurrentUser] User $me): Response
    {
        $this->denyUnless(DossierRightVoter::PURCHASES_ENTER, $dossier);
        $date = $dossier->getDossierDate()->format('Y-m-d');
        $location = StockMovement::MAIN_LOCATION;
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('stock-count-new-'.$dossier->getId(), PostValue::trimmed($request, '_token'))) {
                throw new NotFoundHttpException('Bad token.');
            }
            $date = PostValue::trimmed($request, 'date');
            $location = PostValue::trimmed($request, 'location');
            $opening = StockCountRules::opening($date, $location, $dossier->getDossierDate(), $errors);
            if (null !== $opening) {
                $count = $stockCounts->open($dossier, $opening[0], $opening[1], $me);

                return $this->toCount($count);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('inventory/stock/count_new.html.twig', [
            'dossier' => $dossier,
            'count_date' => DateValue::parse($date),
            'location' => $location,
            'errors' => $errors,
            'places' => $reader->offeredPlaces($dossier),
        ], new Response(status: $status));
    }

    #[Route('/{id}', name: 'app_inventory_stock_count', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Dossier $dossier, int $id, Request $request, StockCountRepository $repository, StockCounts $stockCounts, AssistanceLevels $levels, #[CurrentUser] User $me): Response
    {
        $count = $this->count($dossier, $id, $repository);
        $lines = $count->getLines();
        $errors = [];
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->denyUnless(DossierRightVoter::PURCHASES_ENTER, $dossier);
            if (!$count->isDraft() || !$this->isCsrfTokenValid('stock-count-'.$count->getId(), PostValue::trimmed($request, '_token'))) {
                throw new NotFoundHttpException('Bad token or a validated inventory.');
            }
            $read = StockCountRules::counts($lines, self::typed($request), $errors);
            if (null !== $read) {
                $stockCounts->save($count, $read, $me);
                $this->addFlash('status', 'Comptages enregistrés.');

                return $this->toCount($count);
            }
            $lines = $count->getLines();
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }
        $assistance = Assistance::at($levels->for($dossier));
        $theoretical = $stockCounts->theoretical($count);
        $unjustified = $assistance->requiresStockGapReason() ? StockCountRules::unjustified($lines, $theoretical) : [];
        $articles = $stockCounts->articlesByCode($dossier);
        $rows = [];
        $gaps = 0;
        foreach ($lines as $index => $line) {
            $gap = $line->gapFrom($theoretical[$index]);
            if (null !== $gap && !$gap->isZero()) {
                ++$gaps;
            }
            $rows[] = [
                'index' => $index,
                'line' => $line,
                'article' => $articles[$line->article] ?? null,
                'theoretical' => $theoretical[$index],
                'gap' => $gap,
                'counted_text' => self::typedOf($request, $index, 'counted') ?? (null === $line->counted ? '' : self::plain($line->counted)),
                'reason_text' => self::typedOf($request, $index, 'reason') ?? $line->reason,
                'unjustified' => \in_array($index, $unjustified, true),
            ];
        }

        return $this->render('inventory/stock/count.html.twig', [
            'dossier' => $dossier,
            'count' => $count,
            'line_rows' => $rows,
            'errors' => $errors,
            'gaps' => $gaps,
            'counted' => \count(array_filter($lines, static fn ($line): bool => null !== $line->counted)),
            'refusals' => $count->isDraft() && [] === $errors ? $stockCounts->refusals($count, $assistance) : [],
            'reason_required' => $assistance->requiresStockGapReason(),
        ], new Response(status: $status));
    }

    #[Route('/{id}/validate', name: 'app_inventory_stock_count_validate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validate(Dossier $dossier, int $id, Request $request, StockCountRepository $repository, StockCounts $stockCounts, AssistanceLevels $levels, #[CurrentUser] User $me): Response
    {
        $count = $this->gesture($dossier, $id, $request, $repository, DossierRightVoter::PURCHASES_VALIDATE, 'stock-count-validate-');
        try {
            $stockCounts->validate($count, Assistance::at($levels->for($dossier)), $me);
            $this->addFlash('status', 'Inventaire validé : chaque écart est devenu un ajustement de stock.');
        } catch (StockCountRefused $refused) {
            $this->addFlash('status', 'L’inventaire n’est pas validé : '.implode(' ', $refused->reasons));
        }

        return $this->toCount($count);
    }

    #[Route('/{id}/delete', name: 'app_inventory_stock_count_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Dossier $dossier, int $id, Request $request, StockCountRepository $repository, StockCounts $stockCounts, #[CurrentUser] User $me): Response
    {
        $count = $this->gesture($dossier, $id, $request, $repository, DossierRightVoter::PURCHASES_ENTER, 'stock-count-delete-');
        if (!$count->isDraft()) {
            throw new NotFoundHttpException('A validated inventory is never deleted.');
        }
        $stockCounts->delete($count, $me);
        $this->addFlash('status', 'Inventaire supprimé.');

        return $this->redirectToRoute('app_inventory_stock_counts', ['dossier' => $dossier->getId()], Response::HTTP_SEE_OTHER);
    }

    /** @return array<int, array{counted: string, reason: string}> by line index */
    private static function typed(Request $request): array
    {
        $counted = PostValue::stringsByKey($request, 'counted');
        $reasons = PostValue::stringsByKey($request, 'reason');
        $typed = [];
        foreach ($counted as $index => $text) {
            if (ctype_digit((string) $index)) {
                $typed[(int) $index] = ['counted' => $text, 'reason' => $reasons[$index] ?? ''];
            }
        }

        return $typed;
    }

    /** What the refused form typed in that cell, so that it is shown again; null on a GET. */
    private static function typedOf(Request $request, int $index, string $field): ?string
    {
        if (!$request->isMethod('POST')) {
            return null;
        }

        foreach (PostValue::stringsByKey($request, $field) as $key => $text) {
            if ((string) $index === (string) $key) {
                return $text;
            }
        }

        return null;
    }

    /** « 12,5 »: a quantity as the field shows it, without trailing zeros. */
    private static function plain(BigDecimal $quantity): string
    {
        return str_replace('.', ',', (string) $quantity->strippedOfTrailingZeros());
    }

    private function toCount(StockCount $count): Response
    {
        return $this->redirectToRoute('app_inventory_stock_count', ['dossier' => $count->getDossier()->getId(), 'id' => $count->getId()], Response::HTTP_SEE_OTHER);
    }

    private function count(Dossier $dossier, int $id, StockCountRepository $repository): StockCount
    {
        $this->denyUnless(DossierRightVoter::PURCHASES_READ, $dossier);
        $count = $repository->find($id);
        if (null === $count || $count->getDossier() !== $dossier) {
            throw new NotFoundHttpException('No such inventory.');
        }

        return $count;
    }

    private function gesture(Dossier $dossier, int $id, Request $request, StockCountRepository $repository, string $right, string $token): StockCount
    {
        $count = $this->count($dossier, $id, $repository);
        $this->denyUnless($right, $dossier);
        if (!$this->isCsrfTokenValid($token.$count->getId(), PostValue::trimmed($request, '_token'))) {
            throw new NotFoundHttpException('Bad token.');
        }

        return $count;
    }

    private function denyUnless(string $right, Dossier $dossier): void
    {
        if (!$this->isGranted($right, $dossier)) {
            throw new NotFoundHttpException('Not open to this profile.');
        }
    }
}
