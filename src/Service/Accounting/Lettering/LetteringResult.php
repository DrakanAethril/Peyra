<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;

/** A lettering done: its letter, how many lines took it, what they add up to on the debit side and what is left over. */
final readonly class LetteringResult
{
    public function __construct(
        public string $letter,
        public int $lines,
        public Money $debit,
        public int $gap,
    ) {
    }

    public function isPartial(): bool
    {
        return 0 !== $this->gap;
    }

    /** The sentence of the status bar. */
    public function message(): string
    {
        return $this->isPartial()
            ? \sprintf('Lettrage partiel %s : %d lignes, reste %s au %s.', $this->letter, $this->lines, FrenchFormat::amount(Money::cents(abs($this->gap))), $this->gap > 0 ? 'débit' : 'crédit')
            : \sprintf('Lettrage %s : %d lignes soldées, %s.', $this->letter, $this->lines, FrenchFormat::amount($this->debit));
    }
}
