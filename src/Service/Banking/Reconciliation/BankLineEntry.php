<?php

declare(strict_types=1);

namespace App\Service\Banking\Reconciliation;

use App\Service\Accounting\Ledger\EntryLineDraft;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The lines of the entry a statement line asks for (design-ux.md § 4.5, point 3, « Générer
 * l'écriture »): the bank account on the bank's side of the amount, the account chosen on the
 * other - its VAT split off when a rate is given, the base rounded once to the cent and the VAT
 * the rest, on the deductible VAT for money going out, the collected VAT for money coming in.
 * Pure: the accounts are named by the caller.
 */
final class BankLineEntry
{
    /** VAT on other goods and services: deductible for a payment, collected for a receipt. */
    public const string DEDUCTIBLE_VAT = '445660';
    public const string COLLECTED_VAT = '445710';

    /**
     * @param Money       $amount     the statement line's, signed: positive when money comes in
     * @param string|null $vatRate    percent (« 20 », « 5.5 »), or null for no VAT
     *
     * @return list<EntryLineDraft>
     */
    public static function lines(Money $amount, string $bankAccount, string $account, string $thirdParty, ?string $vatRate, string $label): array
    {
        $whole = $amount->abs();
        $vat = Money::zero();
        if (null !== $vatRate && !BigDecimal::of($vatRate)->isZero()) {
            $base = BigDecimal::of($whole->cents)->multipliedBy(100)->dividedBy(BigDecimal::of(100)->plus($vatRate), 0, RoundingMode::HalfUp);
            $vat = $whole->minus(Money::cents($base->toInt()));
        }
        $net = $whole->minus($vat);
        $out = $amount->isNegative();
        $lines = [$out ? EntryLineDraft::debit($account, $net, $label, $thirdParty) : EntryLineDraft::credit($account, $net, $label, $thirdParty)];
        if (!$vat->isZero()) {
            $lines[] = $out ? EntryLineDraft::debit(self::DEDUCTIBLE_VAT, $vat, $label) : EntryLineDraft::credit(self::COLLECTED_VAT, $vat, $label);
        }
        $lines[] = $out ? EntryLineDraft::credit($bankAccount, $whole, $label) : EntryLineDraft::debit($bankAccount, $whole, $label);

        return $lines;
    }
}
