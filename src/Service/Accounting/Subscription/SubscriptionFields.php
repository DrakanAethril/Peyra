<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

use App\Entity\Accounting\EntryTemplate;
use App\Enum\Accounting\SubscriptionTarget;
use App\Service\Shared\Money;

/** What SubscriptionRules accepted: the subscription ready to be written. */
final readonly class SubscriptionFields
{
    public function __construct(
        public string $label,
        public EntryTemplate $template,
        public ?Money $amount,
        public ?string $vatRate,
        public string $thirdParty,
        public int $day,
        public \DateTimeImmutable $firstMonth,
        public int $occurrences,
        public string $pattern,
        public SubscriptionTarget $target,
    ) {
    }
}
