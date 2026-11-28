<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Entity\Inventory\Article;
use App\Entity\Inventory\StockCount;
use App\Enum\Audit\AuditAction;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Inventory\StockMovementRepository;
use App\Service\Audit\AuditTrail;
use App\Service\Shared\Assistance\Assistance;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of the physical inventories (step 2.5b): open one for a storage place at a day - a
 * line per article whose stock is followed -, save its counts, validate it, delete it while in
 * progress. Its theoretical quantities are read again at each display while in progress, and once
 * more under the articles' locks at its validation, which turns each gap into an adjustment
 * (StockMovements) and freezes them on its lines - all or nothing. Every gesture is in the trail.
 */
final readonly class StockCounts
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArticleRepository $articles,
        private StockMovementRepository $movements,
        private StockReader $reader,
        private StockMovements $stockMovements,
        private AuditTrail $auditTrail,
    ) {
    }

    public function open(Dossier $dossier, \DateTimeImmutable $date, string $location, User|string $by): StockCount
    {
        $lines = [];
        foreach ($this->articles->findByDossier($dossier) as $article) {
            if ($article->isStockTracked()) {
                $lines[] = new StockCountLine($article->getCode());
            }
        }
        $count = new StockCount($dossier, $date, $location, $lines);
        $this->entityManager->persist($count);
        $this->auditTrail->inDossier($dossier, AuditAction::StockCountOpened, $by, \sprintf('%s ouvert : %d article%s', $count->getTitle(), \count($lines), 1 === \count($lines) ? '' : 's'), ['date' => $date->format('Y-m-d'), 'location' => $location]);
        $this->entityManager->flush();

        return $count;
    }

    /**
     * The theoretical quantity of each line, by line index: frozen on a validated inventory, read
     * now on one in progress.
     *
     * @return list<BigDecimal>
     */
    public function theoretical(StockCount $count): array
    {
        $lines = $count->getLines();
        if (!$count->isDraft()) {
            return array_map(static fn (StockCountLine $line): BigDecimal => $line->theoretical ?? BigDecimal::zero(), $lines);
        }
        $quantities = $this->reader->quantitiesAt($count->getDossier(), $count->getDate(), $count->getLocation());
        $articles = $this->articlesByCode($count->getDossier());

        return array_map(static function (StockCountLine $line) use ($articles, $quantities): BigDecimal {
            $article = $articles[$line->article] ?? null;

            return null === $article ? BigDecimal::zero() : ($quantities[(int) $article->getId()] ?? BigDecimal::zero());
        }, $lines);
    }

    /** @return array<string, Article> the dossier's articles by code */
    public function articlesByCode(Dossier $dossier): array
    {
        $byCode = [];
        foreach ($this->articles->findByDossier($dossier) as $article) {
            $byCode[$article->getCode()] = $article;
        }

        return $byCode;
    }

    /** @param list<StockCountLine> $lines what StockCountRules::counts() read */
    public function save(StockCount $count, array $lines, User|string $by): void
    {
        $count->recount($lines);
        $counted = \count(array_filter($lines, static fn (StockCountLine $line): bool => null !== $line->counted));
        $this->auditTrail->inDossier($count->getDossier(), AuditAction::StockCountSaved, $by, \sprintf('%s : %d article%s compté%s sur %d', $count->getTitle(), $counted, $counted > 1 ? 's' : '', $counted > 1 ? 's' : '', \count($lines)), ['count' => $count->getId()]);
        $this->entityManager->flush();
    }

    /** @return list<string> why it may not be validated as it stands, on the quantities read now */
    public function refusals(StockCount $count, Assistance $assistance): array
    {
        $articles = $this->articlesByCode($count->getDossier());

        return StockCountRules::refusals($count->getLines(), $this->theoretical($count), static fn (string $code): bool => ($articles[$code] ?? null)?->isStockTracked() ?? false, $assistance->requiresStockGapReason());
    }

    /**
     * Validated in one transaction: the inventory's row and its articles locked (in id order), the
     * theoretical quantities read again under the locks, the rules and the stock asked again, then an
     * adjustment per gap and the quantities frozen. Refused whole (StockCountRefused) before anything
     * is written, the transaction rolled back and the entity manager left open.
     */
    public function validate(StockCount $count, Assistance $assistance, User|string $by): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->validateLocked($count, $assistance, $by);
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }
    }

    public function delete(StockCount $count, User|string $by): void
    {
        if (!$count->isDraft()) {
            throw new \LogicException('A validated inventory is never deleted.');
        }
        $this->auditTrail->inDossier($count->getDossier(), AuditAction::StockCountDeleted, $by, \sprintf('%s supprimé', $count->getTitle()), ['count' => $count->getId()]);
        $this->entityManager->remove($count);
        $this->entityManager->flush();
    }

    private function validateLocked(StockCount $count, Assistance $assistance, User|string $by): void
    {
        $this->entityManager->refresh($count, LockMode::PESSIMISTIC_WRITE);
        if (!$count->isDraft()) {
            throw new StockCountRefused(['Cet inventaire est déjà validé.']);
        }
        $dossier = $count->getDossier();
        $articles = $this->articlesByCode($dossier);
        $lines = $count->getLines();
        $locked = [];
        foreach ($lines as $line) {
            $article = $articles[$line->article] ?? null;
            if (null !== $article && null !== $line->counted) {
                $locked[(int) $article->getId()] = $article;
            }
        }
        ksort($locked);
        foreach ($locked as $article) {
            $this->entityManager->lock($article, LockMode::PESSIMISTIC_WRITE);
        }
        $theoretical = [];
        foreach ($lines as $line) {
            $article = $articles[$line->article] ?? null;
            $theoretical[] = null === $article ? BigDecimal::zero() : $this->held($article, $count);
        }
        $refusals = StockCountRules::refusals($lines, $theoretical, static fn (string $code): bool => ($articles[$code] ?? null)?->isStockTracked() ?? false, $assistance->requiresStockGapReason());
        $adjustments = [];
        foreach ($lines as $index => $line) {
            $gap = $line->gapFrom($theoretical[$index]);
            $article = $articles[$line->article] ?? null;
            if (null === $gap || $gap->isZero() || null === $article || [] !== $refusals) {
                continue;
            }
            $short = $this->stockMovements->countShortfall($count, $article, $gap);
            if (null !== $short) {
                $refusals[] = $short;
            }
            $adjustments[] = [$article, $gap];
        }
        if ([] !== $refusals) {
            throw new StockCountRefused($refusals);
        }
        foreach ($adjustments as [$article, $gap]) {
            $this->stockMovements->adjustForCount($count, $article, $gap);
        }
        $count->validate(array_map(static fn (StockCountLine $line, BigDecimal $quantity): StockCountLine => $line->frozenAt($quantity), $lines, $theoretical));
        $this->auditTrail->inDossier($dossier, AuditAction::StockCountValidated, $by, \sprintf('%s validé : %d ajustement%s', $count->getTitle(), \count($adjustments), \count($adjustments) > 1 ? 's' : ''), ['count' => $count->getId()]);
        $this->entityManager->flush();
    }

    /** What the inventory's storage place holds of the article at its day, read now. */
    private function held(Article $article, StockCount $count): BigDecimal
    {
        $moves = array_map(static fn ($movement): StockMove => $movement->toMove(), $this->movements->findOfArticle($article, $count->getDate()));
        foreach (StockBalances::byLocation($moves) as $place) {
            if ($place['location'] === $count->getLocation()) {
                return $place['quantity'];
            }
        }

        return BigDecimal::zero();
    }
}
