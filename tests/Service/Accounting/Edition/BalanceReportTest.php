<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Edition;

use App\Service\Accounting\Edition\BalanceLine;
use App\Service\Accounting\Edition\BalanceReport;
use App\Service\Accounting\Ledger\Totals;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/** A balance's lines and totals, on amounts computed by hand. */
final class BalanceReportTest extends TestCase
{
    public function testALineSplitsItsClosingBalance(): void
    {
        // Carried 2 400,00 C, then 2 400,00 D and 4 158,12 C: 4 158,12 C at the end.
        $line = BalanceLine::of(null, null, Money::cents(-240_000), new Totals(Money::cents(240_000), Money::cents(415_812)));

        self::assertSame(-415_812, $line->closing()->cents);
        self::assertSame(0, $line->closingDebit->cents);
        self::assertSame(415_812, $line->closingCredit->cents);
    }

    public function testTotalsAddTheDebitAndTheCreditBalancesApart(): void
    {
        // Class 4: 411000 1 200,00 D, 401000 4 158,12 C, 445660 693,02 D. Class 5: 512000 7 600,00 D.
        $report = BalanceReport::of([
            'Classe 4' => [
                self::line(0, 120_000, 0),
                self::line(-240_000, 240_000, 415_812),
                self::line(0, 69_302, 0),
            ],
            'Classe 5' => [self::line(1_000_000, 0, 240_000)],
            'Classe 6' => [],
        ], true);

        self::assertCount(2, $report->groups, 'An empty group is left out.');
        self::assertSame(['Classe 4', 'Classe 5'], [$report->groups[0]->title, $report->groups[1]->title]);
        $class4 = $report->groups[0]->subtotal;
        self::assertSame(189_302, $class4->closingDebit->cents, '1 200,00 + 693,02');
        self::assertSame(415_812, $class4->closingCredit->cents);
        self::assertSame(-240_000, $class4->opening->cents);
        self::assertSame(429_302, $class4->movements->debit->cents, '1 200,00 + 2 400,00 + 693,02');
        self::assertSame(949_302, $report->total->closingDebit->cents, '1 893,02 + 7 600,00');
        self::assertSame(415_812, $report->total->closingCredit->cents);
        self::assertSame(4, $report->lineCount());
        self::assertTrue($report->hasOpening);
    }

    private static function line(int $opening, int $debit, int $credit): BalanceLine
    {
        return BalanceLine::of(null, null, Money::cents($opening), new Totals(Money::cents($debit), Money::cents($credit)));
    }
}
