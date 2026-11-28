<?php

declare(strict_types=1);

namespace App\Service\Banking\Proposal;

use App\Entity\Banking\Payment;

/** What the generation of a transfer order made (step 2.7): the payments recorded, and each supplier refused with why - a refusal never stops the others. */
final readonly class PaymentRunOutcome
{
    /**
     * @param list<Payment>         $payments
     * @param array<string, string> $refused  by supplier name
     */
    public function __construct(
        public array $payments,
        public array $refused,
    ) {
    }
}
