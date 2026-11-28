<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Document\VatLine;
use App\Service\Shared\Money;

/**
 * A deposit invoice as the final invoice deducts it (step 2.3b): its number and date, its bases
 * and VAT by rate, and whether its VAT was due on receipt - what its entry put on 445800 rather
 * than 445710. Frozen on the final invoice at its validation (SalesDocument::$deposits), so a
 * validated invoice always reads the same deduction.
 */
final readonly class SalesDeposit
{
    /** @param list<VatLine> $vatLines */
    public function __construct(
        public string $number,
        public \DateTimeImmutable $date,
        public array $vatLines,
        public bool $vatOnReceipts,
    ) {
    }

    public function excludingTax(): Money
    {
        return Money::sum(array_map(static fn (VatLine $line): Money => $line->base, $this->vatLines));
    }

    public function vat(): Money
    {
        return Money::sum(array_map(static fn (VatLine $line): Money => $line->vat, $this->vatLines));
    }

    public function includingTax(): Money
    {
        return $this->excludingTax()->plus($this->vat());
    }

    /** @return array{number: string, date: string, vatLines: list<array{rate: string, base: int, vat: int}>, vatOnReceipts: bool} */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'date' => $this->date->format('Y-m-d'),
            'vatLines' => array_map(static fn (VatLine $line): array => $line->toArray(), $this->vatLines),
            'vatOnReceipts' => $this->vatOnReceipts,
        ];
    }

    /** @param array<mixed> $row as toArray() wrote it */
    public static function fromArray(array $row): self
    {
        $number = $row['number'] ?? null;
        $date = \is_string($row['date'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $row['date']) : false;
        $lines = $row['vatLines'] ?? null;
        $onReceipts = $row['vatOnReceipts'] ?? null;
        if (!\is_string($number) || false === $date || !\is_array($lines) || !\is_bool($onReceipts)) {
            throw new \UnexpectedValueException('A stored deposit is {number, date, vatLines, vatOnReceipts}.');
        }

        return new self($number, $date, array_values(array_map(static fn (mixed $line): VatLine => VatLine::fromArray(\is_array($line) ? $line : []), $lines)), $onReceipts);
    }
}
