<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The typed reading of a frozen payslip's JSON (PayslipSnapshot): each key read as what it must be, or
 * an exception - a snapshot is written by the application alone, a key of another type is a bug.
 */
final readonly class SnapshotReader
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : throw self::fault($key);
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return null === $value || \is_string($value) ? $value : throw self::fault($key);
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : throw self::fault($key);
    }

    public function bool(string $key): bool
    {
        $value = $this->data[$key] ?? null;

        return \is_bool($value) ? $value : throw self::fault($key);
    }

    public function money(string $key): Money
    {
        return Money::cents($this->int($key));
    }

    public function nullableMoney(string $key): ?Money
    {
        return null === ($this->data[$key] ?? null) ? null : $this->money($key);
    }

    public function nullableDecimal(string $key): ?BigDecimal
    {
        $value = $this->nullableString($key);

        return null === $value ? null : BigDecimal::of($value);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw self::fault($key);
        }

        return array_values(array_map(static fn (mixed $item): string => \is_string($item) ? $item : throw self::fault($key), $value));
    }

    public function map(string $key): self
    {
        return $this->nullableMap($key) ?? throw self::fault($key);
    }

    public function nullableMap(string $key): ?self
    {
        $value = $this->data[$key] ?? null;
        if (null === $value) {
            return null;
        }

        return \is_array($value) ? new self($value) : throw self::fault($key);
    }

    /** @return list<self> */
    public function maps(string $key): array
    {
        return array_values($this->namedMaps($key));
    }

    /** @return array<string, self> */
    public function namedMaps(string $key): array
    {
        $value = $this->data[$key] ?? [];
        if (!\is_array($value)) {
            throw self::fault($key);
        }
        $maps = [];
        foreach ($value as $name => $item) {
            $maps[(string) $name] = \is_array($item) ? new self($item) : throw self::fault($key);
        }

        return $maps;
    }

    private static function fault(string $key): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('A frozen payslip has no readable « %s ».', $key));
    }
}
