<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * One line of a fiche de stock (step 2.5): the movement, what came in (quantity, unit cost, value)
 * or went out (the same, at the value its method gives), and the stock after it. A transfer moves
 * neither: its line only says where the quantity went.
 */
final readonly class StockCardRow
{
    public function __construct(
        public StockMove $move,
        public ?BigDecimal $inQuantity,
        public ?Money $inValue,
        public ?BigDecimal $outQuantity,
        public ?Money $outValue,
        public ?Money $unitCost,
        public StockPosition $balance,
    ) {
    }
}
