<?php

declare(strict_types=1);

namespace App\Service\Purchasing;

use App\Enum\Purchasing\MatchStatus;
use App\Service\Shared\Money;

/**
 * What InvoiceMatch found for one supplier's pièce: the order matched, the status, the order's
 * lines against its receipts, what was received (at the order's prices) and already invoiced by
 * other pièces, the invoice's gap to the rest (null when not compared), the quantities received
 * short or beyond, the fault that stops the match (null when none) and the sentence of a match.
 */
final readonly class InvoiceMatching
{
    /**
     * @param list<ReceiptLine> $progress
     * @param list<string>      $quantities
     */
    public function __construct(
        public string $order,
        public MatchStatus $status,
        public array $progress,
        public Money $received,
        public Money $invoicedBefore,
        public ?Money $gap,
        public array $quantities,
        public ?string $fault,
        public ?string $matched = null,
    ) {
    }
}
