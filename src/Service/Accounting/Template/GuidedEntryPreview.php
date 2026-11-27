<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Service\Accounting\Entry\EntryControl;
use App\Service\Accounting\Entry\GridCheck;
use App\Service\Shared\Money;

/**
 * The entry the guided entry would write, as the screen shows it before F10: its lines (the
 * journal's counterpart included), each with its account's label and its third party's name, the
 * faults by key (`date`, `document`, `thirdParty`, `amount`, `rate`, `lines`, `line.<i>.<field>`)
 * and the controls of the assistance level.
 *
 * @phpstan-type PreviewLine array{account: string, title: string, thirdParty: string, label: string, debit: Money, credit: Money, automatic: bool}
 */
final readonly class GuidedEntryPreview
{
    /**
     * @param list<PreviewLine>     $lines
     * @param array<string, string> $errors
     * @param list<EntryControl>    $controls
     */
    public function __construct(
        public array $lines,
        public array $errors,
        public array $controls,
        public ?GridCheck $check,
    ) {
    }

    public function debit(): Money
    {
        return Money::sum(array_map(static fn (array $line): Money => $line['debit'], $this->lines));
    }

    public function credit(): Money
    {
        return Money::sum(array_map(static fn (array $line): Money => $line['credit'], $this->lines));
    }

    public function isBalanced(): bool
    {
        return [] !== $this->lines && $this->debit()->equals($this->credit()) && $this->debit()->isPositive();
    }

    /** Nothing stops it: F10 writes it to the brouillard. */
    public function isWritable(): bool
    {
        return [] === $this->errors && null !== $this->check && $this->check->isWritable();
    }

    /** The faults of a line, in a sentence. */
    public function lineErrors(int $index): string
    {
        $messages = [];
        foreach ($this->errors as $key => $message) {
            if (str_starts_with($key, 'line.'.$index.'.')) {
                $messages[] = $message;
            }
        }

        return implode(' ', $messages);
    }

    /** @return list<string> the faults of the entry and of the form's fields - not a line's, said under it */
    public function entryErrors(): array
    {
        $messages = [];
        foreach ($this->errors as $key => $message) {
            if (!str_starts_with($key, 'line.')) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
