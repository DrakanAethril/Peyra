<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/** The side of a line: debit or credit. */
enum EntrySide: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Débit',
            self::Credit => 'Crédit',
        };
    }

    public function other(): self
    {
        return self::Debit === $this ? self::Credit : self::Debit;
    }
}
