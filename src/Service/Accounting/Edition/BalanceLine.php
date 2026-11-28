<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Entity\Accounting\Account;
use App\Entity\ThirdParty\ThirdParty;
use App\Service\Accounting\Ledger\Totals;
use App\Service\Shared\Money;

/**
 * A line of a balance (an account, a third party) or one of its totals: what it carried the eve of
 * the window (a balance, debit minus credit), its movements in the window, and its balance at the
 * end in a debit and a credit column. A total adds the debit balances and the credit balances
 * apart, as a balance prints them: its two columns are equal when the books balance.
 */
final readonly class BalanceLine
{
    private function __construct(
        public ?Account $account,
        public ?ThirdParty $thirdParty,
        public Money $opening,
        public Totals $movements,
        public Money $closingDebit,
        public Money $closingCredit,
    ) {
    }

    public static function of(?Account $account, ?ThirdParty $thirdParty, Money $opening, Totals $movements): self
    {
        $closing = $opening->plus($movements->balance());

        return new self(
            $account,
            $thirdParty,
            $opening,
            $movements,
            $closing->isPositive() ? $closing : Money::zero(),
            $closing->isNegative() ? $closing->negated() : Money::zero(),
        );
    }

    public static function total(): self
    {
        return new self(null, null, Money::zero(), Totals::zero(), Money::zero(), Money::zero());
    }

    /** This total with a line added (accounts and third parties are dropped). */
    public function plus(self $line): self
    {
        return new self(
            null,
            null,
            $this->opening->plus($line->opening),
            $this->movements->plus($line->movements),
            $this->closingDebit->plus($line->closingDebit),
            $this->closingCredit->plus($line->closingCredit),
        );
    }

    /** The balance at the window's end, debit minus credit (for a total: what its lines add up to). */
    public function closing(): Money
    {
        return $this->closingDebit->minus($this->closingCredit);
    }
}
