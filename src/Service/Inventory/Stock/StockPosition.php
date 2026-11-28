<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** An article's stock at a moment (step 2.5): its quantity and its value, all places together. */
final readonly class StockPosition
{
    public function __construct(public BigDecimal $quantity, public Money $value)
    {
    }

    public static function empty(): self
    {
        return new self(BigDecimal::zero(), Money::zero());
    }

    /** The value of one unit, rounded half up to the cent; null when nothing is in stock. */
    public function unitCost(): ?Money
    {
        return self::costOf($this->value, $this->quantity);
    }

    /** A value divided by a quantity, rounded half up to the cent; null for no quantity. */
    public static function costOf(Money $value, BigDecimal $quantity): ?Money
    {
        if (!$quantity->isPositive()) {
            return null;
        }

        return Money::cents(BigDecimal::of($value->cents)->dividedBy($quantity, 0, RoundingMode::HalfUp)->toInt());
    }
}
