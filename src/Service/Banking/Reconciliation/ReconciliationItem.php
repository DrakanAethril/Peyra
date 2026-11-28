<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Service\Shared\Money;

/**
 * One side's movement in a reconciliation, as the rules read it: a statement line (its id) or an
 * entry of the books (the entry's id, its amount the sum of its lines on the bank account). Both
 * signed as the company reads them: positive when money comes in. `letter` is its pointing's.
 */
final readonly class ReconciliationItem
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $date,
        public Money $amount,
        public ?string $letter = null,
    ) {
    }
}
