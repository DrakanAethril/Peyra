<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Service\Regulatory\RegulatorySnapshot;
use Brick\Math\BigDecimal;

/**
 * The due date of a month of pay's DSN (step 4.6), pure: a day of the following month, by the
 * establishment's headcount - the 5th from the threshold, the 15th below it (`social.dsn.due_day`).
 * The contributions are paid and the withholding tax paid back at that date. Neither the late pay
 * nor the move of a due date falling on a day off are read (the value's note says so).
 */
final readonly class SocialDueDate
{
    public function __construct(public BigDecimal $threshold, public int $fromThreshold, public int $belowThreshold)
    {
    }

    public static function from(RegulatorySnapshot $values): self
    {
        return new self(
            $values->decimal('social.dsn.due_day', 'headcount_threshold'),
            $values->integer('social.dsn.due_day', 'from_threshold'),
            $values->integer('social.dsn.due_day', 'below_threshold'),
        );
    }

    /** The due date of that month of pay (its first day, or any of its days) for that headcount. */
    public function of(\DateTimeImmutable $month, BigDecimal $headcount): \DateTimeImmutable
    {
        $day = $headcount->isGreaterThanOrEqualTo($this->threshold) ? $this->fromThreshold : $this->belowThreshold;
        $next = $month->modify('first day of next month');

        return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $day);
    }
}
