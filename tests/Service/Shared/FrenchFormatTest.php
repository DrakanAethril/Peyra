<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

final class FrenchFormatTest extends TestCase
{
    private const string T = "\u{202F}";
    private const string N = "\u{00A0}";
    private const string M = "\u{2212}";

    public function testAmounts(): void
    {
        self::assertSame('3'.self::T.'248,40', FrenchFormat::amount(Money::cents(324840)));
        self::assertSame(self::M.self::N.'138,00', FrenchFormat::amount(Money::cents(-13800)));
        self::assertSame('0,00', FrenchFormat::amount(Money::zero()));
        self::assertSame('0,05', FrenchFormat::amount(Money::cents(5)));
        self::assertSame('1'.self::T.'234'.self::T.'567,89', FrenchFormat::amount(Money::cents(123456789)));
        self::assertSame('459'.self::T.'260,31', FrenchFormat::amount(Money::cents(45926031)));
    }

    public function testCellsLeaveZeroEmpty(): void
    {
        self::assertSame('', FrenchFormat::amountCell(Money::zero()));
        self::assertSame('12,00', FrenchFormat::amountCell(Money::cents(1200)));
        self::assertSame('2'.self::T.'622,00'.self::N.'€', FrenchFormat::amountInEuros(Money::cents(262200)));
    }

    public function testBalanceSaysDebitOrCredit(): void
    {
        self::assertSame('3'.self::T.'248,40'.self::N.'D', FrenchFormat::balance(Money::cents(324840)));
        self::assertSame('138,00'.self::N.'C', FrenchFormat::balance(Money::cents(-13800)));
        self::assertSame('0,00', FrenchFormat::balance(Money::zero()));
    }

    public function testWholeEurosPercentQuantityHours(): void
    {
        self::assertSame('3'.self::T.'956', FrenchFormat::wholeEuros(3956));
        self::assertSame(self::M.self::N.'12', FrenchFormat::wholeEuros(-12));
        self::assertSame('20'.self::N.'%', FrenchFormat::percent('20.00'));
        self::assertSame('1,646'.self::N.'%', FrenchFormat::percent('1.646'));
        self::assertSame('5,5'.self::N.'%', FrenchFormat::percent('5.50'));
        self::assertSame('2,40', FrenchFormat::quantity('2.4'));
        self::assertSame('1'.self::T.'200,000', FrenchFormat::quantity('1200', 3));
        self::assertSame('2,41', FrenchFormat::quantity('2.405'));
        self::assertSame('151,67'.self::N.'h', FrenchFormat::hours('151.67'));
    }

    public function testNumbersKeepTheirDecimals(): void
    {
        self::assertSame('48'.self::T.'060', FrenchFormat::number('48060'));
        self::assertSame('0,3193', FrenchFormat::number('0.3193'));
        self::assertSame('6,9', FrenchFormat::number('6.90'));
        self::assertSame('1'.self::T.'823,03', FrenchFormat::number('1823.03'));
        self::assertSame(self::M.self::N.'2,5', FrenchFormat::number('-2.50'));
        self::assertSame('0', FrenchFormat::number('0.000'));
    }

    public function testDates(): void
    {
        $day = new \DateTimeImmutable('2026-11-12 10:42:00');

        self::assertSame('12/11/2026', FrenchFormat::date($day));
        self::assertSame('12/11', FrenchFormat::dayMonth($day, 2026));
        self::assertSame('12/11/2026', FrenchFormat::dayMonth($day, 2025));
        self::assertSame('12 nov. 2026', FrenchFormat::chipDate($day));
        self::assertSame('1 févr. 2026', FrenchFormat::chipDate(new \DateTimeImmutable('2026-02-01')));
        self::assertSame('jeudi 12 novembre 2026', FrenchFormat::longDate($day));
        self::assertSame('jeudi 1er janvier 2026', FrenchFormat::longDate(new \DateTimeImmutable('2026-01-01')));
        self::assertSame('lundi 30 novembre 2026', FrenchFormat::longDate(new \DateTimeImmutable('2026-11-30')));
        self::assertSame('novembre 2026', FrenchFormat::period($day));
        self::assertSame('août 2026', FrenchFormat::period(new \DateTimeImmutable('2026-08-31')));
        self::assertSame('10'.self::N.'h'.self::N.'42', FrenchFormat::clock($day));
        self::assertSame('9'.self::N.'h'.self::N.'05', FrenchFormat::clock(new \DateTimeImmutable('2026-11-12 09:05')));
    }

    public function testSiretInItsGroups(): void
    {
        self::assertSame('999 412 736 00018', FrenchFormat::siret('99941273600018'));
        self::assertSame('1234', FrenchFormat::siret('1234'));
    }

    public function testDurations(): void
    {
        self::assertSame('35'.self::N.'min', FrenchFormat::duration(35));
        self::assertSame('1'.self::N.'h'.self::N.'42', FrenchFormat::duration(102));
        self::assertSame('2'.self::N.'h', FrenchFormat::duration(120));
        self::assertSame('1'.self::N.'h'.self::N.'05', FrenchFormat::duration(65));
        self::assertSame('0'.self::N.'min', FrenchFormat::duration(-3));
    }
}
