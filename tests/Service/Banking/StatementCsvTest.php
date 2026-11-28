<?php

declare(strict_types=1);

namespace App\Tests\Service\Banking;

use App\Service\Banking\Statement\StatementCsv;
use App\Service\Shared\Money;
use PHPUnit\Framework\TestCase;

/**
 * A statement's CSV file read: the bank's débit and crédit columns, one signed montant, a header
 * or none, every faulty line named; and written back, read again the same.
 */
final class StatementCsvTest extends TestCase
{
    public function testDebitAndCreditColumnsAsTheBankPrintsThem(): void
    {
        $read = StatementCsv::read("Date;Libellé;Débit;Crédit\n03/11/2026;VIR SEPA DUMAS AGENCEMENT;;4 320,00\n05/11/2026;PRLV URSSAF;3 912,00;\n2026-11-18;FRAIS TENUE DE COMPTE;24,00;\n");

        self::assertSame([], $read['errors']);
        self::assertSame([
            ['2026-11-03', 'VIR SEPA DUMAS AGENCEMENT', 432_000],
            ['2026-11-05', 'PRLV URSSAF', -391_200],
            ['2026-11-18', 'FRAIS TENUE DE COMPTE', -2_400],
        ], self::plain($read['lines']));
    }

    public function testASignedAmountWithoutHeader(): void
    {
        $read = StatementCsv::read("09/11/2026,CHQ 7702214,\"-1260,00\"\n20/11/2026,REMISE CHEQUES 0041,2150\n");

        self::assertSame([], $read['errors']);
        self::assertSame([['2026-11-09', 'CHQ 7702214', -126_000], ['2026-11-20', 'REMISE CHEQUES 0041', 215_000]], self::plain($read['lines']));
    }

    public function testEveryFaultyLineIsNamed(): void
    {
        $read = StatementCsv::read("date;libelle;debit;credit\n31/02/2026;X;1;\n01/03/2026;;1;\n02/03/2026;Y;1;2\n03/03/2026;Z;abc;\n04/03/2026;OK;10;\n");

        self::assertSame(['line.2', 'line.3', 'line.4', 'line.5'], array_keys($read['errors']));
        self::assertSame('Ligne 4 : un débit et un crédit à la fois.', $read['errors']['line.4']);
        self::assertCount(1, $read['lines']);
        self::assertSame(['file' => 'Le fichier est vide : une ligne par opération est attendue.'], StatementCsv::read('')['errors']);
    }

    public function testAnExportReadsBackTheSame(): void
    {
        $lines = [
            ['date' => new \DateTimeImmutable('2026-11-14'), 'label' => 'CB STATION U MONTBRISON', 'amount' => Money::cents(-9_640)],
            ['date' => new \DateTimeImmutable('2026-11-20'), 'label' => 'REMISE CHEQUES 0041', 'amount' => Money::cents(215_000)],
        ];
        $csv = StatementCsv::write($lines);

        self::assertStringContainsString("Date;Libellé;Montant\r\n14/11/2026;CB STATION U MONTBRISON;-96,40\r\n", $csv);
        self::assertSame(self::plain($lines), self::plain(StatementCsv::read($csv)['lines']));
    }

    /**
     * @param list<array{date: \DateTimeImmutable, label: string, amount: Money}> $lines
     *
     * @return list<array{string, string, int}>
     */
    private static function plain(array $lines): array
    {
        return array_map(static fn (array $line): array => [$line['date']->format('Y-m-d'), $line['label'], $line['amount']->cents], $lines);
    }
}
