<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Enum\Document\InvoiceFault;
use App\Enum\Document\InvoiceMention;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What an invoice prints (step 2.1b, pure). Each line's amount is its quantity times its price,
 * rounded half up to the cent; the VAT is computed once per rate on the sum of that rate's bases,
 * rounded half up (the French practice); the totals add up. Then the faults asked for:
 *
 * - TVA mal calculée: the VAT of the first rate printed with its two last digits swapped
 *   (541,40 → 541,04) - the total VAT and the total including tax follow the wrong figure;
 * - total faux: the total including tax printed with its euros' units and tens swapped
 *   (3 248,40 → 3 284,40);
 * - when a swap would change nothing (541,44), the figure is raised by ten of its upper digit
 *   (541,54);
 * - a missing mention: the number or the due date are not printed (read as none), the others
 *   only leave the paper.
 */
final class InvoiceCalculator
{
    /** @param \DateTimeImmutable $dueDate the operation's, or the supplier's terms read at its date */
    public static function print(InvoiceOperation $operation, \DateTimeImmutable $dueDate): PrintedInvoice
    {
        $lines = [];
        /** @var array<string, array{rate: BigDecimal, base: Money}> $bases */
        $bases = [];
        foreach ($operation->lines as $line) {
            $amount = $line->amount();
            $lines[] = ['line' => $line, 'amount' => $amount];
            $key = (string) $line->rate->strippedOfTrailingZeros();
            $bases[$key] = ['rate' => $line->rate, 'base' => ($bases[$key]['base'] ?? Money::zero())->plus($amount)];
        }
        uasort($bases, static fn (array $a, array $b): int => $b['rate']->compareTo($a['rate']));

        $vatLines = [];
        foreach ($bases as $key => $row) {
            $vat = $row['base']->times($row['rate']->dividedBy(100, 5), RoundingMode::HalfUp);
            $vatLines[] = new VatLine((string) $key, $row['base'], $vat);
        }
        if ($operation->has(InvoiceFault::VatMiscalculated) && [] !== $vatLines) {
            $first = $vatLines[0];
            $vatLines[0] = new VatLine($first->rate, $first->base, Money::cents(self::misprint($first->vat->cents, 0)));
        }

        $totalExcludingTax = Money::sum(array_map(static fn (array $row): Money => $row['amount'], $lines));
        $totalVat = Money::sum(array_map(static fn (VatLine $line): Money => $line->vat, $vatLines));
        $totalIncludingTax = $totalExcludingTax->plus($totalVat);
        if ($operation->has(InvoiceFault::WrongTotal)) {
            $totalIncludingTax = Money::cents(self::misprint($totalIncludingTax->cents, 2));
        }

        $mentions = array_values(array_filter(InvoiceMention::cases(), static fn (InvoiceMention $mention): bool => $mention !== $operation->missingMention));

        return new PrintedInvoice(
            $lines,
            $vatLines,
            $totalExcludingTax,
            $totalVat,
            $totalIncludingTax,
            InvoiceMention::Number === $operation->missingMention ? null : $operation->number,
            $operation->date,
            InvoiceMention::DueDate === $operation->missingMention ? null : $dueDate,
            $operation->orderReference,
            $mentions,
            $operation->has(InvoiceFault::WrongRecipient),
        );
    }

    /**
     * The figure with its digits at $position and $position + 1 (counted from the right, cents
     * first) swapped; raised by 10^($position + 1) when they are the same.
     */
    public static function misprint(int $cents, int $position): int
    {
        $sign = $cents < 0 ? -1 : 1;
        $digits = str_pad((string) abs($cents), $position + 2, '0', \STR_PAD_LEFT);
        $length = \strlen($digits);
        $low = $length - 1 - $position;
        $high = $low - 1;
        if ($digits[$low] === $digits[$high]) {
            return $sign * (abs($cents) + 10 ** ($position + 1));
        }
        [$digits[$low], $digits[$high]] = [$digits[$high], $digits[$low]];

        return $sign * (int) $digits;
    }
}
