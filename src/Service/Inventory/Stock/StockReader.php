<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Entity\Dossier\Dossier;
use App\Entity\Inventory\Article;
use App\Entity\Inventory\StockMovement;
use App\Repository\Inventory\ArticleRepository;
use App\Repository\Inventory\StockMovementRepository;
use App\Service\Shared\Money;

/**
 * The readings of the stock (step 2.5), each one a replay of the movements by StockValuation -
 * nothing of a stock is stored: an article's fiche de stock to a day, the état du stock of every
 * followed article at a day, the value each movement came in or went out at.
 */
final readonly class StockReader
{
    public function __construct(private StockMovementRepository $movements, private ArticleRepository $articles)
    {
    }

    /** The fiche de stock up to that day. */
    public function card(Article $article, \DateTimeImmutable $until): StockCard
    {
        return StockValuation::card($article->getValuationMethod(), self::moves($this->movements->findOfArticle($article, $until)), $until);
    }

    /** @return list<array{location: string, quantity: \Brick\Math\BigDecimal}> what each storage place holds at that day */
    public function places(Article $article, \DateTimeImmutable $at): array
    {
        return StockBalances::byLocation(self::moves($this->movements->findOfArticle($article, $at)));
    }

    /** @return list<StockLine> every article whose stock is followed, by code, at that day */
    public function state(Dossier $dossier, \DateTimeImmutable $at): array
    {
        $byArticle = [];
        foreach ($this->movements->findUntil($dossier, $at) as $movement) {
            $byArticle[(int) $movement->getArticle()->getId()][] = $movement->toMove();
        }
        $lines = [];
        foreach ($this->articles->findByDossier($dossier) as $article) {
            if (!$article->isStockTracked()) {
                continue;
            }
            $lines[] = new StockLine($article, StockValuation::card($article->getValuationMethod(), $byArticle[(int) $article->getId()] ?? [], $at)->closing);
        }

        return $lines;
    }

    /**
     * The value each movement of the dossier came in or went out at (null for a transfer), by id.
     *
     * @return array<int, Money|null>
     */
    public function values(Dossier $dossier): array
    {
        $byArticle = [];
        $articles = [];
        foreach ($this->movements->findUntil($dossier) as $movement) {
            $article = $movement->getArticle();
            $articles[(int) $article->getId()] = $article;
            $byArticle[(int) $article->getId()][] = $movement->toMove();
        }
        $values = [];
        foreach ($byArticle as $id => $moves) {
            foreach (StockValuation::card($articles[$id]->getValuationMethod(), $moves)->rows as $row) {
                $values[$row->move->key] = $row->inValue ?? $row->outValue;
            }
        }

        return $values;
    }

    /** @return list<string> every storage place a movement of the dossier names, by name */
    public function placesUsed(Dossier $dossier): array
    {
        $places = [];
        foreach ($this->movements->findUntil($dossier) as $movement) {
            $places[$movement->getLocation()] = true;
            if (null !== $movement->getToLocation()) {
                $places[$movement->getToLocation()] = true;
            }
        }
        $names = array_map('strval', array_keys($places));
        sort($names);

        return $names;
    }

    /**
     * @param list<StockMovement> $movements
     *
     * @return list<StockMove>
     */
    private static function moves(array $movements): array
    {
        return array_map(static fn (StockMovement $movement): StockMove => $movement->toMove(), $movements);
    }
}
