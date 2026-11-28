<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Service\Shared\Money;

/** An article ArticleRules accepted, ready to write. */
final readonly class ArticleFields
{
    public function __construct(
        public string $code,
        public string $designation,
        public string $unit,
        public ?Money $salePrice,
        public ?string $vatRate,
        public ?string $revenueAccount,
        public bool $stockTracked,
        public ?Money $purchasePrice = null,
    ) {
    }
}
