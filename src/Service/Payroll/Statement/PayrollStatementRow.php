<?php

declare(strict_types=1);

namespace App\Service\Payroll\Statement;

use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * A line of a payroll state (step 4.5): a rubrique of the journal de paie, of a body's state or of the
 * détail of a total, a line of the clarified payslip in the état des charges - its base when the rows it
 * sums share one unit, its two shares, and the payslips it comes from (their matricules).
 */
final class PayrollStatementRow
{
    /** @var list<string> the matricules of the payslips summed, in their order */
    public array $numbers = [];
    public ?BigDecimal $base = null;
    public Money $employee;
    public Money $employer;
    private bool $mixedBase = false;

    public function __construct(
        public string $key,
        public string $label,
        public ?PayslipUnit $baseUnit = null,
    ) {
        $this->employee = Money::zero();
        $this->employer = Money::zero();
    }

    public function add(PayrollAllocation $allocation): void
    {
        $this->employee = $this->employee->plus($allocation->employee);
        $this->employer = $this->employer->plus($allocation->employer);
        if (!\in_array($allocation->number, $this->numbers, true)) {
            $this->numbers[] = $allocation->number;
        }
        if (null === $allocation->base || $this->mixedBase) {
            return;
        }
        if (null === $this->base) {
            $this->base = $allocation->base;
            $this->baseUnit = $allocation->baseUnit;
        } elseif ($this->baseUnit === $allocation->baseUnit) {
            $this->base = $this->base->plus($allocation->base);
        } else {
            [$this->base, $this->baseUnit, $this->mixedBase] = [null, null, true];
        }
    }

    public function total(): Money
    {
        return $this->employee->plus($this->employer);
    }
}
