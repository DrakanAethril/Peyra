<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Enum\Inventory\StockValuationMethod;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

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
        public StockValuationMethod $valuationMethod = StockValuationMethod::WeightedAverage,
        public ?BigDecimal $minimumStock = null,
    ) {
    }
}
