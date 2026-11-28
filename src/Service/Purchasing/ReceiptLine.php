<?php

declare(strict_types=1);

namespace App\Service\Purchasing;

use App\Service\Sales\SalesLine;
use Brick\Math\BigDecimal;

/**
 * One line of an order and its receipts (ReceiptProgress): its rank in the order, the line as
 * ordered, the quantity received, what remains to receive and what came beyond the order.
 */
final readonly class ReceiptLine
{
    public function __construct(
        public int $index,
        public SalesLine $line,
        public BigDecimal $received,
        public BigDecimal $remaining,
        public BigDecimal $excess,
    ) {
    }
}
