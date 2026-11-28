<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

use App\Enum\Inventory\StockMovementKind;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * A stock movement as the valuation reads it (step 2.5): its key (the movement's id; a movement
 * still to record gets one after every recorded one), its day, its kind, its quantity (an
 * adjustment's signed, every other one positive), an entry's value, its storage place and a
 * transfer's destination.
 */
final readonly class StockMove
{
    public function __construct(
        public int $key,
        public \DateTimeImmutable $date,
        public StockMovementKind $kind,
        public BigDecimal $quantity,
        public ?Money $value,
        public string $location,
        public ?string $toLocation = null,
    ) {
    }

    /** Its effect on the article's quantity, all places together: a transfer moves nothing. */
    public function delta(): BigDecimal
    {
        return match ($this->kind) {
            StockMovementKind::Entry, StockMovementKind::Adjustment => $this->quantity,
            StockMovementKind::Exit => $this->quantity->negated(),
            StockMovementKind::Transfer => BigDecimal::zero(),
        };
    }

    /** @return list<array{0: string, 1: BigDecimal}> its effect on each storage place it touches */
    public function deltas(): array
    {
        if (StockMovementKind::Transfer === $this->kind) {
            return [[$this->location, $this->quantity->negated()], [(string) $this->toLocation, $this->quantity]];
        }

        return [[$this->location, $this->delta()]];
    }

    /** @param list<self> $moves @return list<self> by day, then key */
    public static function sorted(array $moves): array
    {
        usort($moves, static fn (self $a, self $b): int => [$a->date->format('Y-m-d'), $a->key] <=> [$b->date->format('Y-m-d'), $b->key]);

        return $moves;
    }
}
