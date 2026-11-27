<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

/**
 * The Ledger refuses a gesture: an entry that EntryRules does not accept, a validated entry asked
 * to change, a period closed since the draft… The message is the first fault, in French; `errors`
 * holds them all by key (EntryDraft's keys), for a screen that names each under its field.
 */
final class EntryRefused extends \DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct((string) (reset($errors) ?: 'Écriture refusée.'));
    }

    public static function because(string $reason): self
    {
        return new self(['entry' => $reason]);
    }
}
