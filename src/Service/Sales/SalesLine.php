<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * One line of a commercial document (step 2.3): the article it was taken from, by its code (null
 * for a free line), what it sells, how many, at what price excluding tax, the discount granted in
 * percent and the VAT rate (« 20 », « 5.5 »). Port and packing are a line like any other (the
 * article DV001 of the demonstration). Kept as JSON on the document: the article is named by its
 * code, so a copied dossier's lines still name its own articles.
 *
 * A receipt's line names the line of its order it receives (`sourceLine`, its rank in the order's
 * lines - a validated order never changes, so the rank holds): what is left to receive is read from
 * it (step 2.4). Null on every other line, and on a receipt's line the order did not carry.
 */
final readonly class SalesLine
{
    public function __construct(
        public ?string $article,
        public string $designation,
        public string $unit,
        public BigDecimal $quantity,
        public Money $unitPrice,
        public ?BigDecimal $discount,
        public string $rate,
        public ?int $sourceLine = null,
    ) {
    }

    /** Quantity times price, rounded half up to the cent. */
    public function gross(): Money
    {
        return $this->unitPrice->times($this->quantity, RoundingMode::HalfUp);
    }

    /** The discount granted: the gross amount times the percentage, rounded half up. */
    public function discountAmount(): Money
    {
        if (null === $this->discount || $this->discount->isZero()) {
            return Money::zero();
        }

        return $this->gross()->times($this->discount->dividedBy(100, 5), RoundingMode::HalfUp);
    }

    /** The line's amount excluding tax, its discount taken off. */
    public function net(): Money
    {
        return $this->gross()->minus($this->discountAmount());
    }

    /** @return array{article: ?string, designation: string, unit: string, quantity: string, unitPrice: int, discount: ?string, rate: string, source?: int} */
    public function toArray(): array
    {
        $row = [
            'article' => $this->article,
            'designation' => $this->designation,
            'unit' => $this->unit,
            'quantity' => (string) $this->quantity->strippedOfTrailingZeros(),
            'unitPrice' => $this->unitPrice->cents,
            'discount' => null === $this->discount ? null : (string) $this->discount->strippedOfTrailingZeros(),
            'rate' => $this->rate,
        ];
        if (null !== $this->sourceLine) {
            $row['source'] = $this->sourceLine;
        }

        return $row;
    }

    /** @param array<mixed> $row as toArray() wrote it */
    public static function fromArray(array $row): self
    {
        $article = $row['article'] ?? null;
        $designation = $row['designation'] ?? null;
        $unit = $row['unit'] ?? null;
        $quantity = $row['quantity'] ?? null;
        $unitPrice = $row['unitPrice'] ?? null;
        $discount = $row['discount'] ?? null;
        $rate = $row['rate'] ?? null;
        $source = $row['source'] ?? null;
        if ((null !== $source && !\is_int($source)) || (null !== $article && !\is_string($article)) || !\is_string($designation) || !\is_string($unit) || !\is_string($quantity)
            || !\is_int($unitPrice) || (null !== $discount && !\is_string($discount)) || !\is_string($rate)) {
            throw new \UnexpectedValueException('A stored sales line is {article, designation, unit, quantity, unitPrice, discount, rate, source?}.');
        }
        try {
            return new self($article, $designation, $unit, BigDecimal::of($quantity), Money::cents($unitPrice), null === $discount ? null : BigDecimal::of($discount), $rate, $source);
        } catch (MathException $exception) {
            throw new \UnexpectedValueException('A stored sales line holds a number that is not one.', 0, $exception);
        }
    }
}
