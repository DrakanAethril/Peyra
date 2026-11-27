<?php

declare(strict_types=1);

namespace App\Service\Accounting\Ledger;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\ThirdParty;
use App\Service\Shared\Money;

/** A line EntryRules accepted, with its account and third party found. */
final readonly class EntryLineFields
{
    public function __construct(
        public Account $account,
        public ?ThirdParty $thirdParty,
        public string $label,
        public Money $debit,
        public Money $credit,
        public ?\DateTimeImmutable $dueDate,
    ) {
    }
}
