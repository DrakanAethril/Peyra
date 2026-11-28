<?php

declare(strict_types=1);

namespace App\Service\Sales;

use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The one place a commercial document's figures are computed (step 2.3, pure): a line is its
 * quantity times its price rounded half up to the cent, less its discount (that amount times the
 * percentage, rounded half up); the VAT is computed **once per rate** on the sum of that rate's
 * bases, rounded half up (the French practice, as the generator of pièces does); the totals add
 * up. Nothing is stored: a document's figures are read from its lines.
 *
 * Step 2.3b adds the invoice's two deductions. A **cash discount** (« escompte », a percentage of
 * the net commercial amount) is taken off each rate's base, half up, before its VAT: the VAT is
 * due on the net financial amount. The **deposits** already invoiced are deducted from the total
 * including tax: what is left is the amount due.
 */
final class SalesComputation
{
    /**
     * @param list<SalesLine>    $lines
     * @param list<SalesDeposit> $deposits
     */
    public static function of(array $lines, ?BigDecimal $cashDiscount = null, array $deposits = []): SalesTotals
    {
        $amounts = [];
        $discounts = Money::zero();
        /** @var array<string, array{rate: BigDecimal, base: Money}> $bases */
        $bases = [];
        foreach ($lines as $line) {
            $net = $line->net();
            $amounts[] = $net;
            $discounts = $discounts->plus($line->discountAmount());
            $rate = BigDecimal::of($line->rate);
            $key = (string) $rate->strippedOfTrailingZeros();
            $bases[$key] = ['rate' => $rate, 'base' => ($bases[$key]['base'] ?? Money::zero())->plus($net)];
        }
        uasort($bases, static fn (array $a, array $b): int => $b['rate']->compareTo($a['rate']));

        $vatLines = [];
        $escomptes = [];
        foreach ($bases as $key => $row) {
            $escompte = null === $cashDiscount ? Money::zero() : $row['base']->times($cashDiscount->dividedBy(100, 6), RoundingMode::HalfUp);
            $escomptes[] = $escompte;
            $financial = $row['base']->minus($escompte);
            $vatLines[] = new VatLine((string) $key, $financial, $financial->times($row['rate']->dividedBy(100, 5), RoundingMode::HalfUp));
        }
        $excludingTax = Money::sum($amounts);
        $escompte = Money::sum($escomptes);
        $vat = Money::sum(array_map(static fn (VatLine $line): Money => $line->vat, $vatLines));
        $includingTax = $excludingTax->minus($escompte)->plus($vat);
        $deducted = Money::sum(array_map(static fn (SalesDeposit $deposit): Money => $deposit->includingTax(), $deposits));

        return new SalesTotals($amounts, $vatLines, $discounts, $excludingTax, $vat, $includingTax, $escompte, $deposits, $deducted, $includingTax->minus($deducted));
    }
}
