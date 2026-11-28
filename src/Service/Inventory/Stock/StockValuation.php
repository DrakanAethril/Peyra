<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Enum\Inventory\StockMovementKind;
use App\Enum\Inventory\StockValuationMethod;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The fiche de stock of an article (step 2.5, pure): its movements replayed in order - day, then
 * the order they were recorded in - with the article's method. Nothing of it is stored: a movement
 * dated earlier values what follows it again.
 *
 * - An entry adds its quantity and its value.
 * - « CMUP après chaque entrée »: after each entry, the unit cost is the stock's value divided by
 *   its quantity, rounded half up to the cent; an exit is valued at that cost times its quantity,
 *   rounded half up, until the next entry.
 * - « CMUP de fin de période »: the month's cost is (the value at the start of the month + the
 *   month's entries) / (the quantity at the start + the entries' quantity), rounded to the cent;
 *   every exit of the month is valued at it.
 * - PEPS: each entry is a lot; an exit takes the oldest lots first - a whole lot for its value, a
 *   part of one for its value times the share taken, rounded half up.
 * - An exit that empties the stock takes its whole remaining value, so that no cent of rounding
 *   stays behind an empty stock.
 * - A positive adjustment comes in at the cost the next exit would go out at (a PEPS one, at the
 *   last lot's), and never moves the average; a negative one goes out as an exit; a transfer
 *   changes neither the quantity nor the value, only the place.
 */
final class StockValuation
{
    /**
     * @param list<StockMove>         $moves the article's movements up to the day read
     * @param \DateTimeImmutable|null $at    the day read, for the cost of the next exit (the month's average)
     */
    public static function card(StockValuationMethod $method, array $moves, ?\DateTimeImmutable $at = null): StockCard
    {
        $moves = StockMove::sorted($moves);
        $quantity = BigDecimal::zero();
        $value = Money::zero();
        /** @var Money|null $average the weighted average of CMUP, the month's of CMUP de fin de période */
        $average = null;
        /** @var list<array{0: BigDecimal, 1: Money}> $lots PEPS: what is left of each entry, oldest first */
        $lots = [];
        $lastLotCost = null;
        $month = null;
        $entries = StockValuationMethod::PeriodicAverage === $method ? self::entriesByMonth($moves) : [];
        $rows = [];

        foreach ($moves as $move) {
            if (StockValuationMethod::PeriodicAverage === $method && $move->date->format('Y-m') !== $month) {
                $month = $move->date->format('Y-m');
                [$inQuantity, $inValue] = $entries[$month] ?? [BigDecimal::zero(), Money::zero()];
                $average = StockPosition::costOf($value->plus($inValue), $quantity->plus($inQuantity)) ?? $average;
            }
            $kind = $move->kind;
            if (StockMovementKind::Adjustment === $kind && $move->quantity->isNegative()) {
                $kind = StockMovementKind::Exit;
            }
            $taken = $move->quantity->abs();

            if (StockMovementKind::Entry === $kind || StockMovementKind::Adjustment === $kind) {
                if (StockMovementKind::Entry === $kind) {
                    $in = $move->value ?? Money::zero();
                    $cost = StockPosition::costOf($in, $taken);
                } else {
                    $cost = StockValuationMethod::Fifo === $method ? $lastLotCost : $average;
                    $in = null === $cost ? Money::zero() : $cost->times($taken, RoundingMode::HalfUp);
                }
                $quantity = $quantity->plus($taken);
                $value = $value->plus($in);
                if (StockValuationMethod::WeightedAverage === $method && StockMovementKind::Entry === $kind) {
                    $average = StockPosition::costOf($value, $quantity) ?? $average;
                }
                if (StockValuationMethod::Fifo === $method) {
                    $lots[] = [$taken, $in];
                    $lastLotCost = StockPosition::costOf($in, $taken) ?? $lastLotCost;
                }
                $rows[] = new StockCardRow($move, $taken, $in, null, null, $cost, new StockPosition($quantity, $value));
                continue;
            }

            if (StockMovementKind::Exit === $kind) {
                if (StockValuationMethod::Fifo === $method) {
                    [$out, $lots] = self::takeLots($lots, $taken, $lastLotCost);
                    $cost = StockPosition::costOf($out, $taken);
                } else {
                    $out = self::outAtAverage($taken, $quantity, $value, $average);
                    $cost = $average;
                }
                $quantity = $quantity->minus($taken);
                $value = $value->minus($out);
                $rows[] = new StockCardRow($move, null, null, $taken, $out, $cost, new StockPosition($quantity, $value));
                continue;
            }

            $rows[] = new StockCardRow($move, null, null, null, null, null, new StockPosition($quantity, $value));
        }

        $closing = new StockPosition($quantity, $value);
        $next = match ($method) {
            StockValuationMethod::WeightedAverage => $average,
            StockValuationMethod::PeriodicAverage => null !== $at && $at->format('Y-m') !== $month ? ($closing->unitCost() ?? $average) : $average,
            StockValuationMethod::Fifo => [] === $lots ? $lastLotCost : (StockPosition::costOf($lots[0][1], $lots[0][0]) ?? $lastLotCost),
        };

        return new StockCard($rows, $closing, $next);
    }

    /** At the average, or the whole remaining value when the exit empties the stock (what goes beyond it at the average). */
    private static function outAtAverage(BigDecimal $taken, BigDecimal $quantity, Money $value, ?Money $average): Money
    {
        if ($quantity->isPositive() && $taken->isGreaterThanOrEqualTo($quantity)) {
            $beyond = $taken->minus($quantity);

            return $value->plus(null === $average || $beyond->isZero() ? Money::zero() : $average->times($beyond, RoundingMode::HalfUp));
        }

        return null === $average ? Money::zero() : $average->times($taken, RoundingMode::HalfUp);
    }

    /**
     * @param list<array{0: BigDecimal, 1: Money}> $lots
     *
     * @return array{0: Money, 1: list<array{0: BigDecimal, 1: Money}>} the value taken, and the lots left
     */
    private static function takeLots(array $lots, BigDecimal $taken, ?Money $lastLotCost): array
    {
        $out = Money::zero();
        $left = $taken;
        while ($left->isPositive() && [] !== $lots) {
            [$lotQuantity, $lotValue] = $lots[0];
            if ($left->isGreaterThanOrEqualTo($lotQuantity)) {
                $out = $out->plus($lotValue);
                $left = $left->minus($lotQuantity);
                array_shift($lots);
                continue;
            }
            $part = Money::cents(BigDecimal::of($lotValue->cents)->multipliedBy($left)->dividedBy($lotQuantity, 0, RoundingMode::HalfUp)->toInt());
            $out = $out->plus($part);
            $lots[0] = [$lotQuantity->minus($left), $lotValue->minus($part)];
            $left = BigDecimal::zero();
        }
        if ($left->isPositive() && null !== $lastLotCost) {
            $out = $out->plus($lastLotCost->times($left, RoundingMode::HalfUp));
        }

        return [$out, $lots];
    }

    /**
     * @param list<StockMove> $moves
     *
     * @return array<string, array{0: BigDecimal, 1: Money}> each month's entries: quantity and value
     */
    private static function entriesByMonth(array $moves): array
    {
        $months = [];
        foreach ($moves as $move) {
            if (StockMovementKind::Entry !== $move->kind) {
                continue;
            }
            $key = $move->date->format('Y-m');
            [$quantity, $value] = $months[$key] ?? [BigDecimal::zero(), Money::zero()];
            $months[$key] = [$quantity->plus($move->quantity), $value->plus($move->value ?? Money::zero())];
        }

        return $months;
    }
}
