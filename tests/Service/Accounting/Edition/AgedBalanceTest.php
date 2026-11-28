<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Edition;

use App\Enum\Accounting\AgeBand;
use App\Service\Accounting\Edition\AgedBalance;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * The aged balance on cases computed by hand, read at 31/01/2026. A customer's lines: debits are
 * invoices, credits payments; what is owed is the newest invoices.
 */
final class AgedBalanceTest extends TestCase
{
    private const string AT = '2026-01-31';

    public function testTheBandIsCountedFromTheDueDate(): void
    {
        $at = new \DateTimeImmutable(self::AT);

        self::assertSame(AgeBand::NotDue, AgeBand::of(new \DateTimeImmutable('2026-02-14'), $at));
        self::assertSame(AgeBand::NotDue, AgeBand::of(new \DateTimeImmutable('2026-01-31'), $at), 'Due today is not late.');
        self::assertSame(AgeBand::UpTo30, AgeBand::of(new \DateTimeImmutable('2026-01-30'), $at));
        self::assertSame(AgeBand::UpTo30, AgeBand::of(new \DateTimeImmutable('2026-01-01'), $at), '30 days late.');
        self::assertSame(AgeBand::UpTo60, AgeBand::of(new \DateTimeImmutable('2025-12-31'), $at), '31 days late.');
        self::assertSame(AgeBand::UpTo60, AgeBand::of(new \DateTimeImmutable('2025-12-02'), $at), '60 days late.');
        self::assertSame(AgeBand::UpTo90, AgeBand::of(new \DateTimeImmutable('2025-12-01'), $at), '61 days late.');
        self::assertSame(AgeBand::UpTo90, AgeBand::of(new \DateTimeImmutable('2025-11-02'), $at), '90 days late.');
        self::assertSame(AgeBand::Over90, AgeBand::of(new \DateTimeImmutable('2025-11-01'), $at), '91 days late.');
    }

    public function testAPaymentSettlesTheOldestInvoicesFirst(): void
    {
        // Invoices of 4 920,00 (due 31/12/2025) and 8 808,00 (due 31/01/2026), then a payment of
        // 6 000,00: 4 920,00 is paid, then 1 080,00 of the second. Owed: 7 728,00, not yet due.
        $bands = AgedBalance::age([
            self::line(492_000, '2025-11-14', '2025-12-31'),
            self::line(880_800, '2025-12-10', '2026-01-31'),
            self::line(-600_000, '2026-01-20', null),
        ], new \DateTimeImmutable(self::AT));

        self::assertSame(772_800, $bands[AgeBand::NotDue->value]->cents);
        self::assertSame(0, $bands[AgeBand::UpTo60->value]->cents);
        self::assertSame(772_800, Money::sum($bands)->cents);
    }

    public function testTheLastInvoiceTakenIsTakenInPart(): void
    {
        // 1 000,00 due 15/10/2025 (108 days), 2 000,00 due 15/12/2025 (47 days), 500,00 due
        // 20/01/2026 (11 days); 2 700,00 paid. Owed 800,00: the 500,00 whole, then 300,00 of the
        // 2 000,00; the oldest invoice is paid.
        $bands = AgedBalance::age([
            self::line(100_000, '2025-09-15', '2025-10-15'),
            self::line(200_000, '2025-11-15', '2025-12-15'),
            self::line(50_000, '2025-12-20', '2026-01-20'),
            self::line(-270_000, '2026-01-25', null),
        ], new \DateTimeImmutable(self::AT));

        self::assertSame(50_000, $bands[AgeBand::UpTo30->value]->cents);
        self::assertSame(30_000, $bands[AgeBand::UpTo60->value]->cents);
        self::assertSame(0, $bands[AgeBand::Over90->value]->cents);
    }

    public function testALineWithoutDueDateIsAgedFromItsDay(): void
    {
        $bands = AgedBalance::age([self::line(12_000, '2025-10-01', null)], new \DateTimeImmutable(self::AT));

        self::assertSame(12_000, $bands[AgeBand::Over90->value]->cents, '122 days since the 01/10/2025.');
    }

    public function testABalanceOnTheUnusualSideIsAgedOnItsSide(): void
    {
        // A customer who paid 2 862,00 in advance on 05/01/2026, with an old invoice of 100,00
        // settled: credit 2 762,00 net, made of the advance (credits taken, the debit left aside).
        $bands = AgedBalance::age([
            self::line(10_000, '2025-12-01', '2025-12-31'),
            self::line(-286_200, '2026-01-05', null),
        ], new \DateTimeImmutable(self::AT));

        self::assertSame(-276_200, $bands[AgeBand::UpTo30->value]->cents);
        self::assertSame(-276_200, Money::sum($bands)->cents);
    }

    public function testANilBalanceHasNoAge(): void
    {
        $bands = AgedBalance::age([self::line(50_000, '2026-01-02', null), self::line(-50_000, '2026-01-10', null)], new \DateTimeImmutable(self::AT));

        self::assertSame([0, 0, 0, 0, 0], array_values(array_map(static fn (Money $amount): int => $amount->cents, $bands)));
        self::assertSame(array_map(static fn (AgeBand $band): string => $band->value, AgeBand::cases()), array_keys($bands));
    }

    public function testAmongInvoicesOfOneDayTheLastWrittenIsTheNewest(): void
    {
        // Two invoices of the same day, the second due later; 100,00 owed: the second one's.
        $bands = AgedBalance::age([
            self::line(30_000, '2026-01-02', '2026-01-10'),
            self::line(30_000, '2026-01-02', '2026-02-28'),
            self::line(-50_000, '2026-01-20', null),
        ], new \DateTimeImmutable(self::AT));

        self::assertSame(10_000, $bands[AgeBand::NotDue->value]->cents);
        self::assertSame(0, $bands[AgeBand::UpTo30->value]->cents);
    }

    /** @return array{amount: Money, day: \DateTimeImmutable, due: ?\DateTimeImmutable} */
    private static function line(int $cents, string $day, ?string $due): array
    {
        return ['amount' => Money::cents($cents), 'day' => new \DateTimeImmutable($day), 'due' => null === $due ? null : new \DateTimeImmutable($due)];
    }
}
