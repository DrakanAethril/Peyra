<?php

declare(strict_types=1);

namespace App\Service\Banking\Payment;

use App\Entity\Accounting\Entry;
use App\Service\Shared\Money;

/**
 * An invoice a payment may settle (step 2.6b): an entry whose lines on the third party's account are
 * not lettered - a customer's debit, a supplier's credit -, with what payments already took of it
 * and the VAT on receipts still waiting on 445800, due on its earliest line's due date (its date
 * without one; step 2.7).
 */
final readonly class OpenInvoice
{
    public function __construct(
        public Entry $entry,
        public Money $total,
        public Money $allocated,
        public Money $pendingVat,
        public Money $transferredVat,
        public \DateTimeImmutable $dueOn,
    ) {
    }

    public function remaining(): Money
    {
        return $this->total->minus($this->allocated);
    }
}
