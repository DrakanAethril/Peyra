<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The paid leave taken in the month (lot 4, step 4.3): the hours it takes off the salary, the days
 * taken out of the days acquired, and the gross pay of the reference period - what the tenth is
 * counted on. The pay is the better of the salary kept and that tenth (code du travail, L3141-24).
 */
final readonly class PaidLeave
{
    public BigDecimal $hours;
    public BigDecimal $daysTaken;
    public BigDecimal $daysAcquired;

    public function __construct(BigDecimal|string $hours, BigDecimal|string $daysTaken, BigDecimal|string $daysAcquired, public Money $referenceGross)
    {
        $this->hours = BigDecimal::of($hours);
        $this->daysTaken = BigDecimal::of($daysTaken);
        $this->daysAcquired = BigDecimal::of($daysAcquired);
        if ($this->hours->isNegative() || !$this->daysTaken->isPositive() || !$this->daysAcquired->isPositive()
            || $this->daysTaken->isGreaterThan($this->daysAcquired) || $referenceGross->isNegative()) {
            throw new \InvalidArgumentException('Paid leave takes some of the days acquired, on a reference pay that is not negative.');
        }
    }
}
