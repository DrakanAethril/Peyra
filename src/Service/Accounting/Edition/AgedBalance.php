<?php

declare(strict_types=1);

namespace App\Service\Accounting\Edition;

use App\Enum\Accounting\AgeBand;
use App\Service\Shared\Money;

/**
 * How old a third party's balance is (« balance âgée », lot 1, step 1.5b - prepared here,
 * completed by the follow-up of lot 2): a pure function over its lines.
 *
 * The balance is what the lines add up to. It is made of the **most recent** lines on its side:
 * a payment settles the oldest invoices first, so what is still owed is the last invoices, taken
 * from the newest back until the balance is covered (the last one taken in part). Each part falls
 * in the band of its line's due date - the entry's date when it has none - read at `at`. A balance
 * on the unusual side (a customer in credit: an advance, a credit note) is aged the same way, on
 * that side. This needs no lettering, and gives what lettering gives when payments are applied
 * oldest first.
 *
 * Amounts are signed as the ledger signs them: debit positive, credit negative.
 */
final class AgedBalance
{
    /**
     * @param list<array{amount: Money, day: \DateTimeImmutable, due: ?\DateTimeImmutable}> $lines in the order they were written (date, then entry)
     *
     * @return array<string, Money> by AgeBand value, every band present, signed like the balance
     */
    public static function age(array $lines, \DateTimeImmutable $at): array
    {
        $bands = [];
        foreach (AgeBand::cases() as $band) {
            $bands[$band->value] = Money::zero();
        }
        foreach (self::openParts($lines) as $index => $part) {
            $band = AgeBand::of($lines[$index]['due'] ?? $lines[$index]['day'], $at)->value;
            $bands[$band] = $bands[$band]->plus($part);
        }

        return $bands;
    }

    /**
     * The lines the balance is made of - the newest on its side, the last one taken in part - with
     * the part of each that is still open, signed like the balance: what the échéancier lists line by
     * line and the aged balance adds up by band (step 2.7).
     *
     * @param list<array{amount: Money, day: \DateTimeImmutable, due: ?\DateTimeImmutable}> $lines in the order they were written (date, then entry)
     *
     * @return array<int, Money> by index of `$lines`, newest first
     */
    public static function openParts(array $lines): array
    {
        $balance = Money::sum(array_map(static fn (array $line): Money => $line['amount'], $lines));
        if ($balance->isZero()) {
            return [];
        }

        $parts = [];
        $left = $balance->abs();
        $positive = $balance->isPositive();
        $newestFirst = array_keys($lines);
        usort($newestFirst, static fn (int $a, int $b): int => [$lines[$b]['day']->format('Y-m-d'), $b] <=> [$lines[$a]['day']->format('Y-m-d'), $a]);
        foreach ($newestFirst as $index) {
            $amount = $lines[$index]['amount'];
            if ($amount->isZero() || $amount->isPositive() !== $positive) {
                continue;
            }
            $part = $amount->abs()->compareTo($left) < 0 ? $amount->abs() : $left;
            $parts[$index] = $positive ? $part : $part->negated();
            $left = $left->minus($part);
            if ($left->isZero()) {
                break;
            }
        }

        return $parts;
    }
}
