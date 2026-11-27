<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Service\Shared\Money;

/** What a set of entry lines adds up to: its debits, its credits, and their difference. */
final readonly class Totals
{
    public function __construct(
        public Money $debit,
        public Money $credit,
    ) {
    }

    public static function zero(): self
    {
        return new self(Money::zero(), Money::zero());
    }

    /** Debit minus credit: positive is a debit balance (« D »), negative a credit balance (« C »). */
    public function balance(): Money
    {
        return $this->debit->minus($this->credit);
    }

    public function plus(self $other): self
    {
        return new self($this->debit->plus($other->debit), $this->credit->plus($other->credit));
    }

    public function isZero(): bool
    {
        return $this->debit->isZero() && $this->credit->isZero();
    }
}
