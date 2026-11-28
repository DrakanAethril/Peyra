<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use Brick\Math\BigDecimal;

/**
 * The quantities of an article by storage place (step 2.5, pure). A stock never goes below zero:
 * a movement that would empty a place beyond what it holds - on its day or on any later one, since
 * a movement may be dated earlier than others - is refused.
 */
final class StockBalances
{
    /**
     * @param list<StockMove> $moves
     *
     * @return list<array{location: string, quantity: BigDecimal}> each place still holding something, by name
     */
    public static function byLocation(array $moves): array
    {
        $places = [];
        foreach ($moves as $move) {
            foreach ($move->deltas() as [$location, $delta]) {
                $places[$location] = ($places[$location] ?? BigDecimal::zero())->plus($delta);
            }
        }
        $rows = [];
        foreach ($places as $location => $quantity) {
            if (!$quantity->isZero()) {
                $rows[] = ['location' => (string) $location, 'quantity' => $quantity];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['location'], $b['location']));

        return $rows;
    }

    /**
     * The first place and day the movements added would leave short, with what it lacks; null when
     * every place keeps zero or more after every movement.
     *
     * @param list<StockMove> $recorded
     * @param list<StockMove> $added    their keys after every recorded one
     *
     * @return array{location: string, date: \DateTimeImmutable, missing: BigDecimal}|null
     */
    public static function shortfall(array $recorded, array $added): ?array
    {
        $places = [];
        foreach (StockMove::sorted([...$recorded, ...$added]) as $move) {
            foreach ($move->deltas() as [$location, $delta]) {
                $quantity = ($places[$location] ?? BigDecimal::zero())->plus($delta);
                $places[$location] = $quantity;
                if ($quantity->isNegative()) {
                    return ['location' => (string) $location, 'date' => $move->date, 'missing' => $quantity->negated()];
                }
            }
        }

        return null;
    }
}
