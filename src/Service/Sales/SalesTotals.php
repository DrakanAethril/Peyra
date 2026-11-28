<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Document\VatLine;
use App\Service\Shared\Money;

/**
 * What a commercial document adds up to (SalesComputation): each line's amount excluding tax, the
 * VAT by rate - highest rate first - and the three totals.
 */
final readonly class SalesTotals
{
    /**
     * @param list<Money>   $lineAmounts one per line, in the lines' order
     * @param list<VatLine> $vatLines
     */
    public function __construct(
        public array $lineAmounts,
        public array $vatLines,
        public Money $discounts,
        public Money $excludingTax,
        public Money $vat,
        public Money $includingTax,
    ) {
    }
}
