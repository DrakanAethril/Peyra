<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One line of an invoice to generate (step 2.1b): what is sold, how many, at what price excluding
 * tax and at what VAT rate. A discount or a credit is a line of negative price (« Remise 5 % »,
 * 1 × −138,00). Its amount is the quantity times the price, rounded half up to the cent.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $designation,
        public BigDecimal $quantity,
        public Money $unitPrice,
        public BigDecimal $rate,
    ) {
    }

    public function amount(): Money
    {
        return $this->unitPrice->times($this->quantity, RoundingMode::HalfUp);
    }

    /** @return array{designation: string, quantity: string, unitPrice: int, rate: string} */
    public function toArray(): array
    {
        return [
            'designation' => $this->designation,
            'quantity' => (string) $this->quantity->strippedOfTrailingZeros(),
            'unitPrice' => $this->unitPrice->cents,
            'rate' => (string) $this->rate->strippedOfTrailingZeros(),
        ];
    }
}
