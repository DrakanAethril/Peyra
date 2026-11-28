<?php

declare(strict_types=1);

namespace App\Service\Document\Processing;

use App\Service\Accounting\Entry\EntryControl;
use App\Service\Accounting\Entry\GridCheck;

/**
 * A pièce's entry checked as F10 would write it: the grid's checks of the entry (EntryRules, the
 * controls of the level, the due dates it fills) when there is a journal and a month to check it
 * in, the faults the screen names under their keys (`journal`, `date`, `lines`, `line.<i>.<field>`),
 * and whether it was written.
 */
final class PieceCheck
{
    /** @param array<string, string> $errors */
    public function __construct(
        public readonly ?GridCheck $grid,
        public array $errors = [],
        public bool $posted = false,
    ) {
    }

    public function error(string $key): ?string
    {
        return $this->errors[$key] ?? null;
    }

    /** @return list<EntryControl> */
    public function controls(): array
    {
        return null === $this->grid ? [] : $this->grid->controls;
    }

    /** The entry may be written: no fault, no blocking control. */
    public function isWritable(): bool
    {
        return [] === $this->errors && null !== $this->grid && $this->grid->isWritable();
    }
}
