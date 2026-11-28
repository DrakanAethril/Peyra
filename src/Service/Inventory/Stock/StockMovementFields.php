<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Enum\Inventory\StockMovementKind;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/** A stock movement StockMovementRules accepted, ready to record. */
final readonly class StockMovementFields
{
    public function __construct(
        public StockMovementKind $kind,
        public string $article,
        public \DateTimeImmutable $date,
        public BigDecimal $quantity,
        public ?Money $value,
        public string $location,
        public ?string $toLocation,
        public string $label,
    ) {
    }
}
