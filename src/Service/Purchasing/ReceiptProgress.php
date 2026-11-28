<?php

declare(strict_types=1);

namespace App\Service\Purchasing;

use App\Enum\Purchasing\ReceiptStatus;
use App\Service\Sales\SalesLine;
use Brick\Math\BigDecimal;

/**
 * What an order has received and what is left (step 2.4, pure): for each line of the order, the
 * quantity ordered, the sum of what the receipts' lines naming it received, what remains (never
 * below zero) and what was received beyond the order. A receipt line that names no line of the
 * order (`sourceLine` null or out of range) is counted apart: received outside the order.
 */
final class ReceiptProgress
{
    /**
     * @param list<SalesLine>       $order    the order's lines
     * @param list<list<SalesLine>> $receipts the lines of each receipt counted
     *
     * @return list<ReceiptLine> one per line of the order, in its order
     */
    public static function of(array $order, array $receipts): array
    {
        $received = array_fill(0, \count($order), BigDecimal::zero());
        foreach ($receipts as $lines) {
            foreach ($lines as $line) {
                if (null !== $line->sourceLine && isset($received[$line->sourceLine])) {
                    $received[$line->sourceLine] = $received[$line->sourceLine]->plus($line->quantity);
                }
            }
        }
        $rows = [];
        foreach ($order as $i => $line) {
            $left = $line->quantity->minus($received[$i]);
            $rows[] = new ReceiptLine($i, $line, $received[$i], $left->isNegative() ? BigDecimal::zero() : $left, $left->isNegative() ? $left->negated() : BigDecimal::zero());
        }

        return $rows;
    }

    /**
     * The lines of a receipt still to make: each line of the order with something left, its
     * quantity what is left, naming the order's line.
     *
     * @param list<ReceiptLine> $progress
     *
     * @return list<SalesLine>
     */
    public static function toReceive(array $progress): array
    {
        $lines = [];
        foreach ($progress as $row) {
            if ($row->remaining->isPositive()) {
                $line = $row->line;
                $lines[] = new SalesLine($line->article, $line->designation, $line->unit, $row->remaining, $line->unitPrice, $line->discount, $line->rate, $row->index);
            }
        }

        return $lines;
    }

    /** @param list<ReceiptLine> $progress */
    public static function status(array $progress): ReceiptStatus
    {
        $any = false;
        $left = false;
        foreach ($progress as $row) {
            $any = $any || !$row->received->isZero();
            $left = $left || $row->remaining->isPositive();
        }

        return match (true) {
            !$left => ReceiptStatus::Received,
            $any => ReceiptStatus::Partial,
            default => ReceiptStatus::Waiting,
        };
    }

    /**
     * Where a receipt departs from what its order left to receive before it: a line received short
     * or beyond, a line of the order it leaves out, a line outside the order. The sentences the fiche
     * shows when the level flags them (G).
     *
     * @param list<ReceiptLine> $before what the order had received before this receipt
     * @param list<SalesLine>   $receipt
     *
     * @return list<string>
     */
    public static function gaps(array $before, array $receipt): array
    {
        $taken = [];
        $gaps = [];
        foreach ($receipt as $line) {
            $row = null === $line->sourceLine ? null : ($before[$line->sourceLine] ?? null);
            if (null === $row) {
                $gaps[] = \sprintf('« %s » n’est pas sur la commande.', $line->designation);
                continue;
            }
            $taken[$row->index] = ($taken[$row->index] ?? BigDecimal::zero())->plus($line->quantity);
        }
        foreach ($before as $row) {
            if (!$row->remaining->isPositive() && !isset($taken[$row->index])) {
                continue;
            }
            $quantity = $taken[$row->index] ?? BigDecimal::zero();
            if ($quantity->isEqualTo($row->remaining)) {
                continue;
            }
            $gaps[] = $quantity->isGreaterThan($row->remaining)
                ? \sprintf('« %s » : %s reçu pour %s restant à recevoir.', $row->line->designation, self::quantity($quantity), self::quantity($row->remaining))
                : \sprintf('« %s » : %s reçu sur %s restant à recevoir.', $row->line->designation, self::quantity($quantity), self::quantity($row->remaining));
        }

        return $gaps;
    }

    private static function quantity(BigDecimal $quantity): string
    {
        return str_replace('.', ',', (string) $quantity->strippedOfTrailingZeros());
    }
}
