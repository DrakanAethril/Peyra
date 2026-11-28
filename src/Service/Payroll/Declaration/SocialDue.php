<?php

declare(strict_types=1);

namespace App\Service\Payroll\Declaration;

use App\Enum\Payroll\SocialDueState;
use App\Service\Shared\Money;

/**
 * One line of the échéancier social (step 4.6): a payment a month of pay leaves to a body or to the tax
 * office - what it settles (`key` and `account`, as on Écritures de paie), its amount, its due date, the
 * pièce of its payment and where it stands at the dossier's date.
 */
final readonly class SocialDue
{
    public function __construct(
        public \DateTimeImmutable $month,
        public \DateTimeImmutable $dueOn,
        public string $key,
        public string $label,
        public string $account,
        public Money $amount,
        public string $document,
        public SocialDueState $state,
    ) {
    }
}
