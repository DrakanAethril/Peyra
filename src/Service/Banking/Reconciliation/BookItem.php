<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Entity\Accounting\Entry;
use App\Service\Shared\Money;

/**
 * An entry of the books as the reconciliation shows it: its movement on the bank account (the sum
 * of its lines there, signed as the company reads it) and the letter of the statement's pointing
 * that holds it, if any.
 */
final readonly class BookItem
{
    public function __construct(
        public Entry $entry,
        public Money $amount,
        public ?string $letter,
    ) {
    }

    public function item(): ReconciliationItem
    {
        return new ReconciliationItem((int) $this->entry->getId(), $this->entry->getEntryDate(), $this->amount, $this->letter);
    }

    /** The label shown: the entry's. */
    public function label(): string
    {
        return $this->entry->getLabel();
    }
}
