<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Assets\AssetOrigin;
use App\Enum\Assets\DepreciationMethod;
use App\Service\Shared\Money;

/** A fixed asset's fiche as FixedAssetRules accepted it: what FixedAsset::describe() writes. */
final readonly class FixedAssetValues
{
    /**
     * @param array<string, int>                                      $unitsUsed
     * @param list<array{label: string, cost: int, years: int}> $components
     * @param array<string, int>                                      $impairments by the last day of an exercise, in cents
     */
    public function __construct(
        public string $code,
        public string $label,
        public AssetOrigin $origin,
        public Account $account,
        public ?Account $depreciationAccount,
        public \DateTimeImmutable $acquiredOn,
        public \DateTimeImmutable $inServiceOn,
        public Money $price,
        public Money $incidentalCosts,
        public Money $vat,
        public ?ThirdParty $supplier,
        public string $document,
        public Money $residualValue,
        public DepreciationMethod $method,
        public ?int $years,
        public ?int $totalUnits,
        public array $unitsUsed,
        public bool $fiscalDeclining,
        public array $components,
        public array $impairments = [],
    ) {
    }
}
