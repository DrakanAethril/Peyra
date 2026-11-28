<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Service\Assets\Depreciation\PlanRow;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * A fixed asset's plan as FixedAssetPlans read it: its rows by exercise - for an asset by
 * components, their sum, each component's own plan beside -, the declining coefficient it used,
 * or the fault that kept it from being computed.
 */
final readonly class FixedAssetPlan
{
    /**
     * @param list<PlanRow>                                                         $rows
     * @param list<array{label: string, cost: Money, years: int, rows: list<PlanRow>}> $components
     */
    public function __construct(
        public array $rows,
        public array $components = [],
        public ?BigDecimal $coefficient = null,
        public ?string $fault = null,
    ) {
    }

    /** The exercise of the plan that holds the day, if the plan reaches it. */
    public function rowAt(\DateTimeImmutable $day): ?PlanRow
    {
        foreach ($this->rows as $row) {
            if ($row->covers($day)) {
                return $row;
            }
        }

        return null;
    }

    /** The depreciation made by the end of the day's exercise, or by the plan's end when it came first. */
    public function cumulativeAt(\DateTimeImmutable $day): Money
    {
        $cumul = Money::zero();
        foreach ($this->rows as $row) {
            if ($row->start <= $day) {
                $cumul = $row->closing;
            }
        }

        return $cumul;
    }
}
