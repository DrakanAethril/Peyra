<?php

declare(strict_types=1);

namespace App\Service\Accounting\Journal;

use App\Entity\Accounting\Journal;

/** A journal as a person typed it, before JournalSheet checks it, with the error of each field. */
final class JournalDraft
{
    public string $code = '';
    public string $label = '';
    public string $type = '';
    /** The counterpart's number as typed (the account picker fills it). */
    public string $counterpart = '';

    /** @var array<string, string> by field */
    public array $errors = [];

    public static function of(Journal $journal): self
    {
        $draft = new self();
        $draft->code = $journal->getCode();
        $draft->label = $journal->getLabel();
        $draft->type = $journal->getType()->value;
        $draft->counterpart = $journal->getCounterpartAccount()?->getNumber() ?? '';

        return $draft;
    }

    public function error(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }
}
