<?php

declare(strict_types=1);

namespace App\Service\Accounting\Entry;

use App\Service\Accounting\Ledger\EntryDraft;
use App\Service\Accounting\Ledger\EntryFields;

/**
 * What the grid's checks say of one group: the draft it makes, its fields when the Ledger would
 * write it, its faults by key (EntryDraft's), its controls, and the due dates the grid fills
 * (by line index) - what the browser shows under the group, and what F10 decides on.
 */
final readonly class GridCheck
{
    /**
     * @param array<string, string> $errors
     * @param list<EntryControl>    $controls
     * @param array<int, string>    $dues     ISO days by line index, for the lines whose due date was left empty
     */
    public function __construct(
        public EntryDraft $draft,
        public ?EntryFields $fields,
        public array $errors,
        public array $controls,
        public array $dues,
    ) {
    }

    /** It may go to the brouillard: no fault, no blocking control. */
    public function isWritable(): bool
    {
        if (null === $this->fields || [] !== $this->errors) {
            return false;
        }
        foreach ($this->controls as $control) {
            if ($control->blocking) {
                return false;
            }
        }

        return true;
    }

    /** @return array{errors: array<string, string>, controls: list<array{key: string, message: string, blocking: bool}>, dues: list<array{index: int, date: string}>} */
    public function toArray(): array
    {
        $dues = [];
        foreach ($this->dues as $index => $date) {
            $dues[] = ['index' => $index, 'date' => $date];
        }

        return [
            'errors' => $this->errors,
            'controls' => array_map(static fn (EntryControl $control): array => $control->toArray(), $this->controls),
            'dues' => $dues,
        ];
    }
}
