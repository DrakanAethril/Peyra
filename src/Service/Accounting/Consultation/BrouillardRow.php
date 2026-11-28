<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Entry;
use App\Service\Accounting\Entry\EntryControl;

/**
 * A draft as the brouillard lists it (design-ux.md § 3.2): its controls at the dossier's level of
 * assistance, its faults if the Ledger would refuse it now (a month closed since), whether a
 * control stops it (levels G and B: its box cannot be ticked), whether the person is changing it
 * in the grid (held back, like the grid's Ctrl Entrée does).
 */
final readonly class BrouillardRow
{
    /**
     * @param list<EntryControl>    $controls
     * @param array<string, string> $errors
     */
    public function __construct(
        public Entry $entry,
        public array $controls,
        public array $errors,
        public bool $blocked,
        public bool $changing,
    ) {
    }

    public function isSelectable(): bool
    {
        return !$this->blocked && !$this->changing;
    }

    /** The sentence that says why the box cannot be ticked, null when it can. */
    public function reason(): ?string
    {
        if ($this->changing) {
            return 'Vous modifiez cette écriture dans la grille : enregistrez-la (F10) avant de la valider.';
        }
        if ([] !== $this->errors) {
            return array_values($this->errors)[0];
        }
        foreach ($this->controls as $control) {
            if ($control->blocking) {
                return $control->message;
            }
        }

        return null;
    }
}
