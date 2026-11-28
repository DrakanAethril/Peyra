<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

/** A group of a balance - a class of accounts, a kind of third parties - and its subtotal. */
final readonly class BalanceGroup
{
    /** @param list<BalanceLine> $lines */
    public function __construct(
        public string $title,
        public array $lines,
        public BalanceLine $subtotal,
    ) {
    }
}
