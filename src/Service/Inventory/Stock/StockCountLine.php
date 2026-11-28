<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use Brick\Math\BigDecimal;

/**
 * One article of a physical inventory (step 2.5b): its code, the quantity counted (null while it is
 * not - an article left uncounted keeps its stock), the reason given for a gap, and the theoretical
 * quantity of its storage place, frozen when the inventory is validated (null before: a draft reads
 * it again at each display).
 */
final readonly class StockCountLine
{
    public function __construct(
        public string $article,
        public ?BigDecimal $counted = null,
        public string $reason = '',
        public ?BigDecimal $theoretical = null,
    ) {
    }

    /** The counted quantity minus the theoretical one; null while uncounted. */
    public function gapFrom(BigDecimal $theoretical): ?BigDecimal
    {
        return $this->counted?->minus($theoretical);
    }

    public function withCount(?BigDecimal $counted, string $reason): self
    {
        return new self($this->article, $counted, $reason, $this->theoretical);
    }

    public function frozenAt(BigDecimal $theoretical): self
    {
        return new self($this->article, $this->counted, $this->reason, $theoretical);
    }

    /** @return array{article: string, counted: ?string, reason: string, theoretical: ?string} */
    public function toArray(): array
    {
        return [
            'article' => $this->article,
            'counted' => null === $this->counted ? null : (string) $this->counted->toScale(3),
            'reason' => $this->reason,
            'theoretical' => null === $this->theoretical ? null : (string) $this->theoretical->toScale(3),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $decimal = static fn (mixed $value): ?BigDecimal => \is_string($value) && '' !== $value ? BigDecimal::of($value) : null;

        return new self(
            \is_string($row['article'] ?? null) ? $row['article'] : '',
            $decimal($row['counted'] ?? null),
            \is_string($row['reason'] ?? null) ? $row['reason'] : '',
            $decimal($row['theoretical'] ?? null),
        );
    }
}
