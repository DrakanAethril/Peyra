<?php

declare(strict_types=1);

namespace App\Service\Accounting\Journal;

use App\Entity\Accounting\Account;
use App\Enum\Accounting\JournalType;

/** What JournalSheet resolved from a checked draft: the type and the counterpart account. */
final readonly class JournalFields
{
    public function __construct(public JournalType $type, public ?Account $counterpart)
    {
    }
}
