<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Entity\Inventory\Article;

/** One article of the état du stock (step 2.5): its stock at the day read, and whether it fell below its minimum. */
final readonly class StockLine
{
    public function __construct(public Article $article, public StockPosition $position)
    {
    }

    /** Below the article's minimum: « rupture » signalled. An article without a minimum never is. */
    public function isBelowMinimum(): bool
    {
        $minimum = $this->article->getMinimumStock();

        return null !== $minimum && $this->position->quantity->isLessThan($minimum);
    }
}
