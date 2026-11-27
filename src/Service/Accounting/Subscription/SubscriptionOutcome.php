<?php

declare(strict_types=1);

namespace App\Service\Accounting\Subscription;

/**
 * What a generation did: the entries made by target, and for each subscription that stopped, why
 * (its label, the occurrence refused and the reason) - what the list says after the gesture.
 */
final class SubscriptionOutcome
{
    public int $pending = 0;
    public int $drafts = 0;
    /** @var list<string> one sentence per subscription stopped */
    public array $faults = [];

    public function total(): int
    {
        return $this->pending + $this->drafts;
    }
}
