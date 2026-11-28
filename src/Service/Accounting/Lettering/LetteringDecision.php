<?php

declare(strict_types=1);

namespace App\Service\Accounting\Lettering;

/**
 * What LetteringRules decides for a selection: the lines that take the letter (the selection and
 * the whole of every partial group it touches), the letter - in capitals when they balance, in
 * small letters for a partial lettering - and what is left over; or the sentence of a refusal.
 */
final readonly class LetteringDecision
{
    /**
     * @param list<int>    $lineIds
     * @param list<string> $merged  the partial letters the lettering takes over
     */
    private function __construct(
        public ?string $refusal,
        public array $lineIds = [],
        public string $letter = '',
        public int $gap = 0,
        public array $merged = [],
    ) {
    }

    public static function refused(string $refusal): self
    {
        return new self($refusal);
    }

    /**
     * @param list<int>    $lineIds
     * @param list<string> $merged
     */
    public static function letter(array $lineIds, string $letter, int $gap, array $merged): self
    {
        return new self(null, $lineIds, $letter, $gap, $merged);
    }

    public function isPartial(): bool
    {
        return 0 !== $this->gap;
    }
}
