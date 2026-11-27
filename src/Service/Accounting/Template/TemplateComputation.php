<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

use App\Enum\Accounting\EntrySide;
use App\Enum\Accounting\TemplateAmount;
use App\Enum\Accounting\TemplateBase;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * The amounts of an entry template's lines at a call (« formules simples HT, TVA, TTC »,
 * design-ux.md § 3.2) - a pure function over primitives, the one place they are computed:
 *
 * - from an amount before VAT: TVA = HT × taux / 100, rounded to the cent half up; TTC = HT + TVA;
 * - from an amount VAT included: TVA = TTC × taux / (100 + taux), rounded to the cent half up;
 *   HT = TTC − TVA - so that HT + TVA always makes the TTC typed, as on the invoice;
 * - a fixed amount is the line's own; « Solde de l'écriture » is what balances the other lines, on
 *   whichever side balances them (no line when they already balance); « À saisir » has no amount.
 *
 * The VAT is rounded once, on the whole amount: the rounding of a document's VAT (commercial
 * rounding to the cent, half up) is a rule written here and nowhere else.
 */
final class TemplateComputation
{
    /** @return array{net: Money, vat: Money, gross: Money} */
    public static function split(TemplateBase $base, Money $typed, BigDecimal $rate): array
    {
        if (TemplateBase::Net === $base) {
            $vat = Money::cents(BigRational::of($typed->cents)->multipliedBy($rate)->dividedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt());

            return ['net' => $typed, 'vat' => $vat, 'gross' => $typed->plus($vat)];
        }
        $vat = Money::cents(BigRational::of($typed->cents)->multipliedBy($rate)->dividedBy($rate->plus(100))->toScale(0, RoundingMode::HalfUp)->toInt());

        return ['net' => $typed->minus($vat), 'vat' => $vat, 'gross' => $typed];
    }

    /**
     * Each line's side and amount, in the template's order; `amount` null for a line to type (or
     * one computed from an amount not given), a balance line left out when the others balance.
     *
     * @param list<array{side: EntrySide, amount: TemplateAmount, fixed: ?Money}> $lines
     *
     * @return list<array{index: int, side: EntrySide, amount: ?Money}>
     */
    public static function lines(array $lines, ?TemplateBase $base, ?Money $typed, ?BigDecimal $rate): array
    {
        $split = null !== $base && null !== $typed && null !== $rate ? self::split($base, $typed, $rate) : null;
        $computed = [];
        $gap = Money::zero();
        foreach ($lines as $index => $line) {
            $amount = match ($line['amount']) {
                TemplateAmount::Net => $split['net'] ?? null,
                TemplateAmount::Vat => $split['vat'] ?? null,
                TemplateAmount::Gross => $split['gross'] ?? null,
                TemplateAmount::Fixed => $line['fixed'],
                TemplateAmount::Balance, TemplateAmount::Typed => null,
            };
            if (null !== $amount) {
                $gap = EntrySide::Debit === $line['side'] ? $gap->plus($amount) : $gap->minus($amount);
            }
            $computed[$index] = ['index' => $index, 'side' => $line['side'], 'amount' => $amount];
        }

        $result = [];
        foreach ($lines as $index => $line) {
            if (TemplateAmount::Balance !== $line['amount']) {
                $result[] = $computed[$index];
                continue;
            }
            if ($gap->isZero()) {
                continue;
            }
            // What balances the entry: a debit gap is filled on the credit side, and the other way round.
            $result[] = ['index' => $index, 'side' => $gap->isPositive() ? EntrySide::Credit : EntrySide::Debit, 'amount' => $gap->abs()];
            $gap = Money::zero();
        }

        return $result;
    }
}
