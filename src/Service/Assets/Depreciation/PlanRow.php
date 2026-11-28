<?php

declare(strict_types=1);

namespace App\Service\Assets\Depreciation;

use App\Service\Shared\Money;

/**
 * One exercise of a depreciation plan: its bounds, the time counted (days for the linear method,
 * months for the declining one, units for the units of work), the depreciation before it, its
 * dotation, after it and the net book value at its end. Beside a linear plan with a fiscal
 * declining one: the fiscal annuity and the dérogatoire - positive a dotation, negative a reprise -
 * and its balance at the end. `impairment`: the dépréciation's balance at its end, once there is one
 * (the net book value is then net of it).
 */
final readonly class PlanRow
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public int $counted,
        public Money $opening,
        public Money $dotation,
        public Money $closing,
        public Money $netValue,
        public bool $switched = false,
        public ?Money $fiscal = null,
        public ?Money $derogatory = null,
        public ?Money $derogatoryBalance = null,
        public ?Money $impairment = null,
    ) {
    }

    /** Whether the day falls in this exercise. */
    public function covers(\DateTimeImmutable $day): bool
    {
        return $this->start <= $day && $day <= $this->end;
    }
}
