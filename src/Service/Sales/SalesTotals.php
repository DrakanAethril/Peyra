<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Document\VatLine;
use App\Service\Shared\Money;

/**
 * What a commercial document adds up to (SalesComputation): each line's amount excluding tax, the
 * VAT by rate - highest rate first, each on its net financial base -, the net commercial amount
 * (`excludingTax`), the cash discount taken off it, the total including tax, the deposits it
 * deducts and the amount still due.
 */
final readonly class SalesTotals
{
    /**
     * @param list<Money>        $lineAmounts one per line, in the lines' order
     * @param list<VatLine>      $vatLines
     * @param list<SalesDeposit> $deposits
     */
    public function __construct(
        public array $lineAmounts,
        public array $vatLines,
        public Money $discounts,
        public Money $excludingTax,
        public Money $vat,
        public Money $includingTax,
        public Money $cashDiscount,
        public array $deposits,
        public Money $depositsDeducted,
        public Money $amountDue,
    ) {
    }

    /** The net financial amount: the net commercial amount less the cash discount. */
    public function netFinancial(): Money
    {
        return $this->excludingTax->minus($this->cashDiscount);
    }
}
