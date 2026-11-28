<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory\Stock;

use App\Enum\Inventory\StockMovementKind;
use App\Enum\Inventory\StockValuationMethod;
use App\Service\Inventory\Stock\StockBalances;
use App\Service\Inventory\Stock\StockCard;
use App\Service\Inventory\Stock\StockMove;
use App\Service\Inventory\Stock\StockValuation;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * The fiche de stock by each method (step 2.5), on cases computed by hand: the expected amounts
 * are written here, never read from the code.
 */
final class StockValuationTest extends TestCase
{
    public function testWeightedAverageAfterEachEntry(): void
    {
        // 100 at 10,00; out 30 at 10,00 = 300,00; in 50 at 12,00 = 600,00 -> 120 for 1 300,00, CMUP 1 300 / 120 = 10,8333 -> 10,83;
        // out 40 at 10,83 = 433,20 -> 80 for 866,80; out 80 empties the stock and takes 866,80.
        $card = StockValuation::card(StockValuationMethod::WeightedAverage, [
            self::in(1, '2026-01-01', '100', 100_000),
            self::out(2, '2026-01-05', '30'),
            self::in(3, '2026-01-10', '50', 60_000),
            self::out(4, '2026-01-15', '40'),
            self::out(5, '2026-01-20', '80'),
        ]);

        self::assertSame([null, 30_000, null, 43_320, 86_680], self::outValues($card));
        self::assertSame([1_000, 1_000, 1_200, 1_083, 1_083], self::costs($card));
        self::assertSame([100_000, 70_000, 130_000, 86_680, 0], self::balanceValues($card));
        self::assertSame('0', (string) $card->closing->quantity->strippedOfTrailingZeros());
        self::assertSame(1_083, $card->nextExitCost?->cents);
    }

    public function testTheOrderOfTheDayThenOfRecording(): void
    {
        // The exit recorded first but dated after both entries is valued after them: 1 600,00 / 150 = 10,6667 -> 10,67.
        $card = StockValuation::card(StockValuationMethod::WeightedAverage, [
            self::out(1, '2026-01-12', '30'),
            self::in(2, '2026-01-01', '100', 100_000),
            self::in(3, '2026-01-10', '50', 60_000),
        ]);

        self::assertSame([2, 3, 1], array_map(static fn ($row): int => $row->move->key, $card->rows));
        self::assertSame(32_010, $card->rows[2]->outValue?->cents, '30 × 10,67 = 320,10');
    }

    public function testPeriodicAverageOfTheMonth(): void
    {
        // January: (0 + 1 000,00 + 600,00) / (0 + 100 + 50) = 10,6667 -> 10,67 for every exit of the month:
        // 30 × 10,67 = 320,10, 40 × 10,67 = 426,80 -> 80 for 1 600,00 − 746,90 = 853,10.
        // February: no entry, 853,10 / 80 = 10,66375 -> 10,66; 20 × 10,66 = 213,20 -> 60 for 639,90.
        $card = StockValuation::card(StockValuationMethod::PeriodicAverage, [
            self::in(1, '2026-01-01', '100', 100_000),
            self::out(2, '2026-01-05', '30'),
            self::in(3, '2026-01-10', '50', 60_000),
            self::out(4, '2026-01-15', '40'),
            self::out(5, '2026-02-03', '20'),
        ], new \DateTimeImmutable('2026-02-28'));

        self::assertSame([null, 32_010, null, 42_680, 21_320], self::outValues($card));
        self::assertSame(63_990, $card->closing->value->cents);
        self::assertSame(1_066, $card->nextExitCost?->cents);
        // Read in March, the month's average is the stock's own: 639,90 / 60 = 10,665 -> 10,67.
        $march = StockValuation::card(StockValuationMethod::PeriodicAverage, self::periodicMoves(), new \DateTimeImmutable('2026-03-10'));
        self::assertSame(1_067, $march->nextExitCost?->cents);
    }

    public function testFirstInFirstOut(): void
    {
        // The double glazing MP014 of the demonstration: two lots in stock (14,30 m² for 869,44 and 24,20 for 1 500,40),
        // the receipt of 26/03 (18,40 at 63,50 = 1 168,40), then 20 m² out: the first lot whole, 5,70 of the second
        // for 1 500,40 × 5,70 / 24,20 = 353,40 -> 1 222,84; left 18,50 at 62,00 + 18,40 at 63,50 = 1 147,00 + 1 168,40.
        $card = StockValuation::card(StockValuationMethod::Fifo, [
            self::in(1, '2026-01-01', '14.30', 86_944),
            self::in(2, '2026-01-01', '24.20', 150_040),
            self::in(3, '2026-03-26', '18.40', 116_840),
            self::out(4, '2026-03-30', '20'),
        ]);

        self::assertSame(122_284, $card->rows[3]->outValue?->cents);
        self::assertSame(6_114, $card->rows[3]->unitCost?->cents, '1 222,84 / 20 = 61,142');
        self::assertSame('36.9', (string) $card->closing->quantity->strippedOfTrailingZeros());
        self::assertSame(231_540, $card->closing->value->cents);
        self::assertSame(6_200, $card->nextExitCost?->cents, 'The oldest lot left is the second one, at 62,00.');
    }

    public function testFirstInFirstOutRoundsEachPartAndLeavesNothingBehind(): void
    {
        // A lot of 3 for 100,00: 1 out = 33,33; then 1 of the 2 left for 66,67 = 33,335 -> 33,34; the last takes 33,33.
        $card = StockValuation::card(StockValuationMethod::Fifo, [
            self::in(1, '2026-01-01', '3', 10_000),
            self::out(2, '2026-01-02', '1'),
            self::out(3, '2026-01-03', '1'),
            self::out(4, '2026-01-04', '1'),
        ]);

        self::assertSame([null, 3_333, 3_334, 3_333], self::outValues($card));
        self::assertSame(0, $card->closing->value->cents);
    }

    public function testAdjustmentsAndTransfers(): void
    {
        // CMUP 10,83 after the second entry; +5 found comes in at 10,83 = 54,15 and leaves the average alone;
        // −2 goes out at 10,83 = 21,66; a transfer moves nothing.
        $card = StockValuation::card(StockValuationMethod::WeightedAverage, [
            self::in(1, '2026-01-01', '70', 70_000),
            self::in(2, '2026-01-10', '50', 60_000),
            new StockMove(3, new \DateTimeImmutable('2026-01-11'), StockMovementKind::Adjustment, BigDecimal::of('5'), null, 'Dépôt principal'),
            new StockMove(4, new \DateTimeImmutable('2026-01-12'), StockMovementKind::Adjustment, BigDecimal::of('-2'), null, 'Dépôt principal'),
            new StockMove(5, new \DateTimeImmutable('2026-01-13'), StockMovementKind::Transfer, BigDecimal::of('10'), null, 'Dépôt principal', 'Atelier'),
        ]);

        self::assertSame(5_415, $card->rows[2]->inValue?->cents);
        self::assertSame(2_166, $card->rows[3]->outValue?->cents);
        self::assertNull($card->rows[4]->inValue);
        self::assertNull($card->rows[4]->outValue);
        self::assertSame(130_000 + 5_415 - 2_166, $card->closing->value->cents);
        self::assertSame('123', (string) $card->closing->quantity->strippedOfTrailingZeros());
        self::assertSame(1_083, $card->nextExitCost?->cents);
    }

    public function testPlacesAndShortfall(): void
    {
        $recorded = [
            self::in(1, '2026-01-01', '10', 10_000),
            self::out(2, '2026-01-10', '8'),
            new StockMove(3, new \DateTimeImmutable('2026-01-02'), StockMovementKind::Transfer, BigDecimal::of('1'), null, 'Dépôt principal', 'Atelier'),
        ];

        $places = StockBalances::byLocation($recorded);
        self::assertSame(['Atelier', 'Dépôt principal'], array_column($places, 'location'));
        self::assertSame(['1', '1'], array_map(static fn (array $row): string => (string) $row['quantity']->strippedOfTrailingZeros(), $places));

        // 5 taken on the 5th leaves 4 in the main place on the 5th, but −4 on the 10th, after the exit of 8 already recorded.
        $short = StockBalances::shortfall($recorded, [self::out(100, '2026-01-05', '5')]);
        self::assertNotNull($short);
        self::assertSame(['Dépôt principal', '2026-01-10', '4'], [$short['location'], $short['date']->format('Y-m-d'), (string) $short['missing']->strippedOfTrailingZeros()]);
        self::assertNull(StockBalances::shortfall($recorded, [self::out(100, '2026-01-12', '1')]));
        self::assertNotNull(StockBalances::shortfall($recorded, [new StockMove(100, new \DateTimeImmutable('2026-01-12'), StockMovementKind::Transfer, BigDecimal::of('2'), null, 'Atelier', 'Chantier')]), 'The workshop holds 1.');
    }

    /** @return list<StockMove> */
    private static function periodicMoves(): array
    {
        return [
            self::in(1, '2026-01-01', '100', 100_000),
            self::out(2, '2026-01-05', '30'),
            self::in(3, '2026-01-10', '50', 60_000),
            self::out(4, '2026-01-15', '40'),
            self::out(5, '2026-02-03', '20'),
        ];
    }

    private static function in(int $key, string $date, string $quantity, int $cents): StockMove
    {
        return new StockMove($key, new \DateTimeImmutable($date), StockMovementKind::Entry, BigDecimal::of($quantity), Money::cents($cents), 'Dépôt principal');
    }

    private static function out(int $key, string $date, string $quantity): StockMove
    {
        return new StockMove($key, new \DateTimeImmutable($date), StockMovementKind::Exit, BigDecimal::of($quantity), null, 'Dépôt principal');
    }

    /** @return list<int|null> */
    private static function outValues(StockCard $card): array
    {
        return array_map(static fn ($row): ?int => $row->outValue?->cents, $card->rows);
    }

    /** @return list<int|null> */
    private static function costs(StockCard $card): array
    {
        return array_map(static fn ($row): ?int => $row->unitCost?->cents, $card->rows);
    }

    /** @return list<int> */
    private static function balanceValues(StockCard $card): array
    {
        return array_map(static fn ($row): int => $row->balance->value->cents, $card->rows);
    }
}
