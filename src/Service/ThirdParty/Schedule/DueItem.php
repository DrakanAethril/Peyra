<?php

declare(strict_types=1);

namespace App\Service\ThirdParty\Schedule;

use App\Entity\Accounting\EntryLine;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Accounting\AgeBand;
use App\Service\Shared\Money;

/**
 * One line of the échéancier (step 2.7): a line of a third party still open at the day - the whole
 * of it or the part the balance keeps -, due on its due date (the entry's date without one). Its
 * amounts are signed as the kind reads them: what a customer owes, what is owed to a supplier,
 * positive; an advance or a credit note left on the unusual side, negative.
 */
final readonly class DueItem
{
    public function __construct(
        public ThirdParty $thirdParty,
        public EntryLine $line,
        public \DateTimeImmutable $dueOn,
        public Money $lineAmount,
        public Money $open,
        public AgeBand $band,
        public int $daysLate,
    ) {
    }

    public function isOverdue(): bool
    {
        return AgeBand::NotDue !== $this->band;
    }
}
