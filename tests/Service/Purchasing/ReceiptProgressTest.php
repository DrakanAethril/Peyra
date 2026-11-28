<?php

declare(strict_types=1);

namespace App\Tests\Service\Purchasing;

use App\Enum\Purchasing\ReceiptStatus;
use App\Service\Purchasing\ReceiptProgress;
use App\Service\Sales\SalesLine;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * What an order received and what is left (step 2.4), on the order CF-0001 of the demonstration
 * (operations-2026.md § 2.2): chêne 27 mm 1,80 m³ at 1 150,00 and chêne 54 mm 1,20 m³ at 1 240,00,
 * both 5 % off.
 */
final class ReceiptProgressTest extends TestCase
{
    public function testTwoReceiptsLeaveWhatIsLeft(): void
    {
        $progress = ReceiptProgress::of(self::order(), [
            [self::line('1', 0)],
            [self::line('0.5', 0), self::line('1.2', 1), self::line('3', null)],
        ]);

        self::assertSame(['1.5', '0.3', '0'], [(string) $progress[0]->received->strippedOfTrailingZeros(), (string) $progress[0]->remaining->strippedOfTrailingZeros(), (string) $progress[0]->excess]);
        self::assertSame(['1.2', '0', '0'], [(string) $progress[1]->received->strippedOfTrailingZeros(), (string) $progress[1]->remaining->strippedOfTrailingZeros(), (string) $progress[1]->excess]);
        self::assertSame(ReceiptStatus::Partial, ReceiptProgress::status($progress), 'A line outside the order counts for nothing.');

        $next = ReceiptProgress::toReceive($progress);
        self::assertCount(1, $next);
        self::assertSame(['0.3', 115_000, '5', 0], [(string) $next[0]->quantity->strippedOfTrailingZeros(), $next[0]->unitPrice->cents, (string) $next[0]->discount, $next[0]->sourceLine], 'The next receipt takes what is left, at the order’s price.');
    }

    public function testStatusAndExcess(): void
    {
        self::assertSame(ReceiptStatus::Waiting, ReceiptProgress::status(ReceiptProgress::of(self::order(), [])));
        $over = ReceiptProgress::of(self::order(), [[self::line('2', 0), self::line('1.2', 1)]]);
        self::assertSame(ReceiptStatus::Received, ReceiptProgress::status($over));
        self::assertSame(['0', '0.2'], [(string) $over[0]->remaining->strippedOfTrailingZeros(), (string) $over[0]->excess->strippedOfTrailingZeros()]);
        self::assertSame([], ReceiptProgress::toReceive($over));
    }

    public function testTheGapsOfAReceiptAgainstWhatWasLeft(): void
    {
        $before = ReceiptProgress::of(self::order(), []);

        self::assertSame([], ReceiptProgress::gaps($before, [self::line('1.8', 0), self::line('1.2', 1)]));
        self::assertSame([
            '« Palette » n’est pas sur la commande.',
            '« Chêne avivé 27 mm » : 1,5 reçu sur 1,8 restant à recevoir.',
            '« Chêne avivé 54 mm » : 0 reçu sur 1,2 restant à recevoir.',
        ], ReceiptProgress::gaps($before, [self::line('1.5', 0), new SalesLine(null, 'Palette', 'pièce', BigDecimal::one(), Money::zero(), null, '20')]));
        self::assertSame(['« Chêne avivé 54 mm » : 1,3 reçu pour 1,2 restant à recevoir.'], ReceiptProgress::gaps($before, [self::line('1.8', 0), self::line('1.3', 1)]));
    }

    /** @return list<SalesLine> */
    private static function order(): array
    {
        return [
            new SalesLine('MP001', 'Chêne avivé 27 mm', 'm³', BigDecimal::of('1.8'), Money::cents(115_000), BigDecimal::of('5'), '20'),
            new SalesLine('MP003', 'Chêne avivé 54 mm', 'm³', BigDecimal::of('1.2'), Money::cents(124_000), BigDecimal::of('5'), '20'),
        ];
    }

    private static function line(string $quantity, ?int $source): SalesLine
    {
        $order = self::order();
        $of = $order[$source ?? 0];

        return new SalesLine($of->article, $of->designation, $of->unit, BigDecimal::of($quantity), $of->unitPrice, $of->discount, $of->rate, $source);
    }
}
