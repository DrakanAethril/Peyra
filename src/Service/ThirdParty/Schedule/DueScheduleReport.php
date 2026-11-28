<?php

declare(strict_types=1);

namespace App\Service\ThirdParty\Schedule;

use App\Enum\ThirdParty\ThirdPartyKind;
use App\Service\Shared\Money;

/** The échéancier of a kind of third party at a day (step 2.7): its open lines, earliest due first, and their totals. */
final readonly class DueScheduleReport
{
    /** @param list<DueItem> $items */
    public function __construct(
        public ThirdPartyKind $kind,
        public \DateTimeImmutable $at,
        public array $items,
    ) {
    }

    public function total(): Money
    {
        return Money::sum(array_map(static fn (DueItem $item): Money => $item->open, $this->items));
    }

    public function overdue(): Money
    {
        return Money::sum(array_map(static fn (DueItem $item): Money => $item->open, array_filter($this->items, static fn (DueItem $item): bool => $item->isOverdue())));
    }

    public function notDue(): Money
    {
        return $this->total()->minus($this->overdue());
    }

    /**
     * What each third party has overdue, by its id: the « Échu » column of the lists of customers
     * and suppliers.
     *
     * @return array<int, Money>
     */
    public function overdueByThirdParty(): array
    {
        $by = [];
        foreach ($this->items as $item) {
            if ($item->isOverdue()) {
                $id = (int) $item->thirdParty->getId();
                $by[$id] = ($by[$id] ?? Money::zero())->plus($item->open);
            }
        }

        return $by;
    }

    /** @return list<DueItem> */
    public function overdueItems(): array
    {
        return array_values(array_filter($this->items, static fn (DueItem $item): bool => $item->isOverdue()));
    }
}
