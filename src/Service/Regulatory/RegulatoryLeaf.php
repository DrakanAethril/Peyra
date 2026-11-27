<?php

declare(strict_types=1);

namespace App\Service\Regulatory;

/** One leaf of a regulatory value (RegulatoryTree): where it sits, and its number or text. */
final readonly class RegulatoryLeaf
{
    /** @param list<int|string> $path map keys and list indexes from the root; empty for a plain value */
    public function __construct(public array $path, public string|bool|null $value)
    {
    }

    public function isNumber(): bool
    {
        return RegulatoryTree::isNumber($this->value);
    }

    /** « sales_and_accommodation › base », « n° 2 › rate »; empty for a plain value. */
    public function pathLabel(): string
    {
        return implode(' › ', array_map(static fn (int|string $segment): string => \is_int($segment) ? 'n° '.($segment + 1) : $segment, $this->path));
    }
}
