<?php

declare(strict_types=1);

namespace App\Service\Assets\Disposal;

use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Assets\DisposalKind;
use App\Service\Shared\Money;

/** A way out as DisposalRules accepted it: what FixedAsset::dispose() writes. */
final readonly class DisposalValues
{
    public function __construct(
        public DisposalKind $kind,
        public \DateTimeImmutable $on,
        public Money $price,
        public Money $vat,
        public ?ThirdParty $buyer,
    ) {
    }
}
