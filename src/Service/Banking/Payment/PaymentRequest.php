<?php

declare(strict_types=1);

namespace App\Service\Banking\Payment;

use App\Enum\Banking\PaymentDirection;
use App\Enum\ThirdParty\PaymentMethod;
use App\Service\Shared\Money;

/** A payment as the form sends it, before Payments checks it (step 2.6b). */
final class PaymentRequest
{
    /** @param list<int> $invoices the invoices' entries, in the order they are settled */
    public function __construct(
        public PaymentDirection $direction,
        public ?PaymentMethod $method = null,
        public string $thirdParty = '',
        public string $journal = '',
        public ?\DateTimeImmutable $date = null,
        public string $reference = '',
        public ?Money $amount = null,
        public ?\DateTimeImmutable $dueOn = null,
        public array $invoices = [],
    ) {
    }
}
