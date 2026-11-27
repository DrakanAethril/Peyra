<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * An amount of euros, held as a whole number of cents (README of the plan, § 8: no float for
 * money). Stored in a BIGINT column by App\Doctrine\Type\MoneyType (`#[ORM\Column(type: 'money')]`).
 *
 * Every operation is exact; the only one that can lose a fraction of a cent, a multiplication by a
 * rate or a quantity, names its rounding: each fiscal or social rounding is a rule, written once by
 * whoever applies it, never a default (conception § 5). Arithmetic goes through brick/math, so an
 * overflow throws instead of turning into a float as PHP's own `+` would.
 */
final readonly class Money implements \JsonSerializable
{
    private function __construct(
        public int $cents,
    ) {
    }

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Euros as an exact decimal (« 1234.56 », a BigDecimal), rounded to the cent by the rule given.
     * Unnecessary (the default) refuses a third decimal rather than guess what it should become.
     */
    public static function euros(BigNumber|int|string $euros, RoundingMode $rounding = RoundingMode::Unnecessary): self
    {
        return new self(BigDecimal::of($euros)->toScale(2, $rounding)->getUnscaledValue()->toInt());
    }

    /** @param iterable<self> $amounts */
    public static function sum(iterable $amounts): self
    {
        $total = BigInteger::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus($amount->cents);
        }

        return new self($total->toInt());
    }

    public function plus(self $other): self
    {
        return new self(BigInteger::of($this->cents)->plus($other->cents)->toInt());
    }

    public function minus(self $other): self
    {
        return new self(BigInteger::of($this->cents)->minus($other->cents)->toInt());
    }

    public function negated(): self
    {
        return new self(BigInteger::of($this->cents)->negated()->toInt());
    }

    public function abs(): self
    {
        return $this->cents < 0 ? $this->negated() : $this;
    }

    /**
     * The amount times a rate or a quantity (« 0.20 », « 2.40 »), rounded to the cent by the rule the
     * caller names: there is no default, a rounding is always somebody's decision.
     */
    public function times(BigNumber|int|string $factor, RoundingMode $rounding): self
    {
        return self::euros($this->toDecimal()->multipliedBy($factor), $rounding);
    }

    /** The euros as an exact decimal of scale 2 (« 1234.56 »). */
    public function toDecimal(): BigDecimal
    {
        return BigDecimal::ofUnscaledValue($this->cents, 2);
    }

    public function isZero(): bool
    {
        return 0 === $this->cents;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /** -1, 0 or 1. */
    public function compareTo(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    /** The cents, as JSON carries them: an integer, never a decimal a browser would read as a float. */
    public function jsonSerialize(): int
    {
        return $this->cents;
    }
}
