<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Enum\ThirdParty\ThirdPartyKind;

/** The aged balance of a kind of third parties at a day: a row per third party whose balance is not nil, by code, and the total. */
final readonly class AgedBalanceReport
{
    /** @param list<AgedBalanceRow> $rows */
    public function __construct(
        public ThirdPartyKind $kind,
        public \DateTimeImmutable $at,
        public array $rows,
        public AgedBalanceRow $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }
}
