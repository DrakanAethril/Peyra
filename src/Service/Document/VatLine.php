<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Service\Shared\Money;

/**
 * One line of a pièce's VAT breakdown as read on it: the rate (« 20 », « 5.5 », VatRate's
 * canonical text), the base excluding tax and the VAT printed for it. Read, never computed: a
 * supplier's miscalculated VAT is read as printed (step 2.1b's faulty pièces).
 */
final readonly class VatLine
{
    public function __construct(
        public string $rate,
        public Money $base,
        public Money $vat,
    ) {
    }

    /** @return array{rate: string, base: int, vat: int} */
    public function toArray(): array
    {
        return ['rate' => $this->rate, 'base' => $this->base->cents, 'vat' => $this->vat->cents];
    }

    /** @param array<mixed> $row as toArray() wrote it */
    public static function fromArray(array $row): self
    {
        $rate = $row['rate'] ?? null;
        $base = $row['base'] ?? null;
        $vat = $row['vat'] ?? null;
        if (!\is_string($rate) || !\is_int($base) || !\is_int($vat)) {
            throw new \UnexpectedValueException('A stored VAT line is {rate: string, base: int, vat: int}.');
        }

        return new self($rate, Money::cents($base), Money::cents($vat));
    }
}
