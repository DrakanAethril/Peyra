<?php

declare(strict_types=1);

namespace App\Service\Document\Preparation;

use App\Enum\Document\SlipNature;
use App\Enum\Document\SlipPlace;

/**
 * The panel opened on one value of the pièce in « mode préparation » (design-ux.md § 3.5): to slip
 * an error - its nature (null when none may fall on it), the right value, where it may show (the
 * default first), three plausible wrong values, every third party a wrong one may name (`parties`) -,
 * and, on an account of the right proposal while no error shows in the entry, to fix it (`line`).
 */
final readonly class SlipPanel
{
    /**
     * @param list<SlipPlace>                            $places
     * @param list<array{value: string, label: string}> $suggestions
     * @param list<array{value: string, label: string}> $parties
     */
    public function __construct(
        public string $target,
        public string $label,
        public ?SlipNature $nature,
        public string $right,
        public array $places,
        public array $suggestions,
        public array $parties,
        public ?int $line,
        public string $account,
    ) {
    }
}
