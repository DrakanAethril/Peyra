<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Fec;

use App\Service\Accounting\Fec\FecFormat;
use App\Service\Accounting\Fec\FecWriter;
use App\Service\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FecFormatTest extends TestCase
{
    public function testAmountsHaveADecimalCommaAndNoThousandsSeparator(): void
    {
        self::assertSame('1234567,89', FecFormat::amount(Money::cents(123_456_789)));
        self::assertSame('0,00', FecFormat::amount(Money::zero()));
        self::assertSame('0,05', FecFormat::amount(Money::cents(5)));
        self::assertSame('-138,00', FecFormat::amount(Money::cents(-13_800)));
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function amounts(): iterable
    {
        yield 'cents' => ['1234,56', 123_456];
        yield 'whole' => ['1234', 123_400];
        yield 'one decimal' => ['12,5', 1_250];
        yield 'leading minus' => ['-12,50', -1_250];
        yield 'trailing minus' => ['12,50-', -1_250];
        yield 'plus' => ['+3,00', 300];
        yield 'decimal point' => ['1234.56', null];
        yield 'thousands' => ['1 234,56', null];
        yield 'three decimals' => ['1,234', null];
        yield 'two signs' => ['-1,00-', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('amounts')]
    public function testAmountsAreReadBack(string $text, ?int $cents): void
    {
        self::assertSame($cents, FecFormat::parseAmount($text)?->cents);
    }

    public function testDaysAreEightFiguresOfARealDay(): void
    {
        self::assertSame('20260131', FecFormat::day(new \DateTimeImmutable('2026-01-31')));
        self::assertSame('2026-02-28', FecFormat::parseDay('20260228')?->format('Y-m-d'));
        self::assertNull(FecFormat::parseDay('20260230'));
        self::assertNull(FecFormat::parseDay('2026-01-31'));
        self::assertNull(FecFormat::parseDay('31012026x'));
    }

    public function testTheFileIsNamedAfterTheSirenAndTheClosingDay(): void
    {
        self::assertSame('999412736FEC20261231.txt', FecFormat::fileName('999412736', new \DateTimeImmutable('2026-12-31')));
    }

    public function testTheWriterNamesTheZonesThenWritesOneLineEachWithoutSeparatorsInAZone(): void
    {
        $text = FecWriter::write([['AN', 'À-nouveaux', 'AN-2026-00001', '20260101', '512000', 'Banque', '', '', 'AN', '20260101', "Solde\td’ouverture\r\n", '100,00', '0,00', '', '', '20260131', '', '']]);

        self::assertSame(
            "JournalCode\tJournalLib\tEcritureNum\tEcritureDate\tCompteNum\tCompteLib\tCompAuxNum\tCompAuxLib\tPieceRef\tPieceDate\tEcritureLib\tDebit\tCredit\tEcritureLet\tDateLet\tValidDate\tMontantdevise\tIdevise\r\n"
            ."AN\tÀ-nouveaux\tAN-2026-00001\t20260101\t512000\tBanque\t\t\tAN\t20260101\tSolde d’ouverture\t100,00\t0,00\t\t\t20260131\t\t\r\n",
            $text,
        );
    }

    public function testTheWriterRefusesALineThatIsNotEighteenZones(): void
    {
        $this->expectException(\LogicException::class);
        FecWriter::write([['AN', 'À-nouveaux']]);
    }
}
