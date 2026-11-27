<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;

/**
 * The regulatory values in force on one day (RegulatoryReader::at()), read typed: a calculation
 * asks for a decimal, a rate, an amount, a count or a text, at a path inside a structure when the
 * value is one (« sales_and_accommodation », « base »). Anything absent, or of the wrong kind,
 * throws MissingRegulatoryValue: there is no default.
 */
final readonly class RegulatorySnapshot
{
    /** @param array<string, RegulatoryValue> $values in force on $day, by key */
    public function __construct(public \DateTimeImmutable $day, private array $values)
    {
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function value(string $key): RegulatoryValue
    {
        return $this->values[$key] ?? throw new MissingRegulatoryValue(\sprintf('No regulatory value « %s » in force on %s.', $key, $this->day->format('Y-m-d')));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /** « 6.9 » for 6,90 %: the number as written, whatever its unit. */
    public function decimal(string $key, int|string ...$path): BigDecimal
    {
        $leaf = $this->leaf($key, $path);
        if (!RegulatoryTree::isNumber($leaf)) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s »%s is not a number.', $key, self::where($path)));
        }

        return BigDecimal::of((string) $leaf);
    }

    /** 0.069 for 6,90 %: a value written in percent, as a fraction. */
    public function rate(string $key, int|string ...$path): BigDecimal
    {
        if (!str_starts_with($this->value($key)->getUnit(), 'percent')) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s » is not written in percent (unit « %s »).', $key, $this->value($key)->getUnit()));
        }
        $percent = $this->decimal($key, ...$path);

        return $percent->dividedBy(100, $percent->getScale() + 2);
    }

    /** An amount in euros, refused if it has more than two decimals (no rounding happens here). */
    public function money(string $key, int|string ...$path): Money
    {
        try {
            return Money::euros($this->decimal($key, ...$path), RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s »%s is not an amount in cents.', $key, self::where($path)));
        }
    }

    public function integer(string $key, int|string ...$path): int
    {
        $decimal = $this->decimal($key, ...$path)->strippedOfTrailingZeros();
        if ($decimal->getScale() > 0) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s »%s is not a whole number.', $key, self::where($path)));
        }

        return $decimal->toInt();
    }

    public function text(string $key, int|string ...$path): string
    {
        $leaf = $this->leaf($key, $path);
        if (!\is_string($leaf) || RegulatoryTree::isNumber($leaf)) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s »%s is not a text.', $key, self::where($path)));
        }

        return $leaf;
    }

    /** @param list<int|string> $path */
    private function leaf(string $key, array $path): string|bool
    {
        $node = RegulatoryTree::at($this->value($key)->getTree(), $path);
        if (\is_array($node)) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s »%s is a structure, not a leaf.', $key, self::where($path)));
        }
        if (null === $node) {
            throw new MissingRegulatoryValue(\sprintf('Regulatory value « %s » has nothing%s.', $key, self::where($path)));
        }

        return $node;
    }

    /** @param list<int|string> $path */
    private static function where(array $path): string
    {
        return [] === $path ? '' : ' at '.implode('.', $path);
    }
}
