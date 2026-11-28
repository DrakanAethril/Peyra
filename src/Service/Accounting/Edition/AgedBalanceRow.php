<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AgeBand;
use App\Service\Shared\Money;

/**
 * A third party of the aged balance, or its total: what it owes or is owed, and how that splits
 * into the age bands. Amounts are read in the kind's own direction - a customer's debit, a
 * supplier's credit, positive - so a negative amount is a balance on the unusual side.
 */
final readonly class AgedBalanceRow
{
    /** @param array<string, Money> $bands by AgeBand value, every band present */
    public function __construct(
        public ?ThirdParty $thirdParty,
        public Money $total,
        public array $bands,
    ) {
    }

    public static function total(): self
    {
        $bands = [];
        foreach (AgeBand::cases() as $band) {
            $bands[$band->value] = Money::zero();
        }

        return new self(null, Money::zero(), $bands);
    }

    public function plus(self $row): self
    {
        $bands = [];
        foreach (AgeBand::cases() as $band) {
            $bands[$band->value] = $this->band($band)->plus($row->band($band));
        }

        return new self(null, $this->total->plus($row->total), $bands);
    }

    public function band(AgeBand $band): Money
    {
        return $this->bands[$band->value] ?? Money::zero();
    }

    /** What is overdue: every band but « Non échu ». */
    public function overdue(): Money
    {
        return $this->total->minus($this->band(AgeBand::NotDue));
    }
}
