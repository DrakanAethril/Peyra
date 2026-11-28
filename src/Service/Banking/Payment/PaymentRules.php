<?php

declare(strict_types=1);

namespace App\Service\Banking\Payment;

use App\Service\Shared\Money;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * The figures of a payment (step 2.6b), pure: how its amount spreads over the invoices chosen, the
 * VAT on receipts it moves, what a discounted bill brings in.
 */
final class PaymentRules
{
    /**
     * The amount spread over the invoices in the order chosen, each up to what remains due on it;
     * null when it exceeds them all (a payment on account names no invoice). An invoice left with
     * nothing is still named: the person chose it.
     *
     * @param list<Money> $remainings
     *
     * @return list<Money>|null
     */
    public static function allocate(Money $amount, array $remainings): ?array
    {
        $left = $amount;
        $parts = [];
        foreach ($remainings as $remaining) {
            $part = $left->compareTo($remaining) < 0 ? $left : $remaining;
            $parts[] = $part;
            $left = $left->minus($part);
        }

        return $left->isPositive() ? null : $parts;
    }

    /**
     * The VAT on receipts a payment moves from 445800 to 445710 for one invoice (conception § 5;
     * DECISIONS 2.3b): the invoice's pending VAT in proportion to what is received, rounded to the
     * nearest cent (half up); the payment that completes the invoice takes what is left, so that
     * the invoice's VAT is moved whole, never a cent more.
     */
    public static function vatTransfer(Money $pendingVat, Money $invoiceTotal, Money $allocatedBefore, Money $transferredBefore, Money $allocated): Money
    {
        if (!$pendingVat->isPositive() || !$invoiceTotal->isPositive() || !$allocated->isPositive()) {
            return Money::zero();
        }
        if ($allocatedBefore->plus($allocated)->compareTo($invoiceTotal) >= 0) {
            $rest = $pendingVat->minus($transferredBefore);

            return $rest->isPositive() ? $rest : Money::zero();
        }
        $share = BigRational::of($pendingVat->cents)->multipliedBy($allocated->cents)->dividedBy($invoiceTotal->cents)->toScale(0, RoundingMode::HalfUp)->toInt();

        return Money::cents($share);
    }

    /** What the bank credits for a bill discounted: its amount less the interest, the commission and its VAT. */
    public static function discountNet(Money $nominal, Money $interest, Money $commission, Money $commissionVat): Money
    {
        return $nominal->minus($interest)->minus($commission)->minus($commissionVat);
    }
}
