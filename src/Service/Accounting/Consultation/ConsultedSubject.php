<?php

declare(strict_types=1);

namespace App\Service\Accounting\Consultation;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\ThirdParty;

/**
 * What a consultation reads (design-ux.md § 3.2, « Consultation d'un compte »): an account of the
 * plan, or a third party - all its lines, whatever the account they sit on (its collective, 416000,
 * 419100…). Typed as the grid types it: a number, an auxiliary code (« 401SCIE »), or the third
 * party's code when it has none (« A0001 »).
 */
final readonly class ConsultedSubject
{
    private function __construct(
        public ?Account $account,
        public ?ThirdParty $thirdParty,
    ) {
    }

    public static function account(Account $account): self
    {
        return new self($account, null);
    }

    public static function thirdParty(ThirdParty $thirdParty): self
    {
        return new self(null, $thirdParty);
    }

    /** What is typed to find it again: « 512200 », « 401SCIE », « A0001 ». */
    public function code(): string
    {
        if (null !== $this->account) {
            return $this->account->getNumber();
        }
        \assert(null !== $this->thirdParty);

        return $this->thirdParty->getAuxiliaryCode() ?? $this->thirdParty->getCode();
    }

    public function label(): string
    {
        return $this->account?->getLabel() ?? (string) $this->thirdParty?->getName();
    }

    public function isThirdParty(): bool
    {
        return null !== $this->thirdParty;
    }
}
