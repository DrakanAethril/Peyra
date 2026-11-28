<?php

declare(strict_types=1);

namespace App\Service\Assets\Depreciation;

use App\Enum\Assets\DepreciationMethod;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * What a depreciation plan is computed from (DepreciationPlan): primitives only.
 *
 * - `windows`: the exercises the plan is laid on, contiguous, in order (the dossier's own, then
 *   twelve months at a time before and after them - FixedAssetPlans);
 * - the linear method counts from the day of putting into service, the declining one from the
 *   first day of the month of acquisition (fiscal rule, art. 39 A CGI);
 * - `coefficient`: the declining method's fiscal coefficient, read at the day of acquisition; also
 *   the fiscal plan's when `fiscalDeclining` asks for one beside a linear plan (dérogatoire);
 * - `units`: the units of work consumed, by the last day of a window (Y-m-d);
 * - `impairments`: the impairment's balance (« dépréciation ») at the last day of a window (Y-m-d),
 *   carried to the next ones until another is given; from the window after one, the annuities spread
 *   what is left to depreciate over the time left (PCG art. 214-17, prospective).
 */
final readonly class PlanInput
{
    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $windows
     * @param array<string, int>                                               $units
     * @param array<string, Money>                                             $impairments
     */
    public function __construct(
        public DepreciationMethod $method,
        public Money $cost,
        public Money $residual,
        public \DateTimeImmutable $acquiredOn,
        public \DateTimeImmutable $inServiceOn,
        public array $windows,
        public ?int $years = null,
        public ?BigDecimal $coefficient = null,
        public ?int $totalUnits = null,
        public array $units = [],
        public ?\DateTimeImmutable $disposedOn = null,
        public bool $fiscalDeclining = false,
        public array $impairments = [],
    ) {
    }

    /** What is depreciated: the cost less the residual value. */
    public function base(): Money
    {
        return $this->cost->minus($this->residual);
    }
}
