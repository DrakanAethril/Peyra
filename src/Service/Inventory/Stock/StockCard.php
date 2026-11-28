<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Service\Shared\Money;

/**
 * An article's fiche de stock (step 2.5), as StockValuation reads it: its lines, the stock after
 * the last one, and the unit cost the next exit would be valued at (the current CMUP, the month's,
 * or the oldest lot still in stock) - null when there is nothing to value it by.
 */
final readonly class StockCard
{
    /** @param list<StockCardRow> $rows */
    public function __construct(public array $rows, public StockPosition $closing, public ?Money $nextExitCost)
    {
    }
}
