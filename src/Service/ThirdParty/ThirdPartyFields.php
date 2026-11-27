<?php

declare(strict_types=1);

namespace App\Service\ThirdParty;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\PaymentTerms;
use App\Enum\ThirdParty\PaymentMethod;
use App\Enum\ThirdParty\ThirdPartyKind;

/** What ThirdPartyRules resolved from a checked draft; the draft's texts are normalized beside it. */
final readonly class ThirdPartyFields
{
    public function __construct(
        public ThirdPartyKind $kind,
        public ?Account $account,
        public ?string $auxiliaryCode,
        public PaymentTerms $terms,
        public PaymentMethod $method,
    ) {
    }
}
