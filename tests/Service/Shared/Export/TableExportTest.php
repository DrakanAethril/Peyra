<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Export;

use App\Service\Shared\Export\Column;
use App\Service\Shared\Export\ColumnKind;
use App\Service\Shared\Export\ExportFormat;
use App\Service\Shared\Export\ExportTable;
use App\Service\Shared\Export\TableExport;
use App\Service\Shared\Money;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

/**
 * A list leaves for a spreadsheet: CSV that a French spreadsheet reads as numbers and days, XLSX
 * whose amounts are numbers with two decimals, days dates, codes text.
 */
final class TableExportTest extends TestCase
{
    public function testTheCsvWritesAmountsADayAndEmptyCells(): void
    {
        $csv = TableExport::csv($this->table());

        self::assertSame("\u{FEFF}Date;Compte;Libellé;Débit;Crédit;Solde\r\n"
            ."08/01/2026;401000;\"Scierie; facture\";;4158,12;-4158,12\r\n"
            ."20/01/2026;00012;Règlement;2400,00;;-1758,12\r\n"
            ."31/01/2026;512000;;;;0,00\r\n", $csv);
    }

    public function testAnAmountIsWrittenWithoutThousandsAndWithAnAsciiMinus(): void
    {
        self::assertSame('1234567,89', TableExport::csvAmount(Money::cents(123_456_789)));
        self::assertSame('-0,05', TableExport::csvAmount(Money::cents(-5)));
        self::assertSame('0,00', TableExport::csvAmount(Money::zero()));
    }

    public function testTheXlsxHoldsNumbersDatesAndCodesAsText(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, TableExport::xlsx($this->table()));
        try {
            $reader = new Reader();
            $reader->open($path);
            $rows = [];
            $names = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }
            $reader->close();
        } finally {
            @unlink($path);
        }

        self::assertSame(['401SCIE Scierie du Haut-Forez'], $names);
        self::assertSame(['Date', 'Compte', 'Libellé', 'Débit', 'Crédit', 'Solde'], $rows[0]);
        self::assertInstanceOf(\DateTimeInterface::class, $rows[1][0]);
        self::assertSame('2026-01-08', $rows[1][0]->format('Y-m-d'));
        self::assertSame('401000', $rows[1][1]);
        self::assertSame('00012', $rows[2][1]);
        self::assertEqualsWithDelta(4158.12, $rows[1][4], 0.0001);
        self::assertEqualsWithDelta(-4158.12, $rows[1][5], 0.0001);
        self::assertSame('', $rows[1][3]);
        self::assertEqualsWithDelta(0.0, $rows[3][5], 0.0001);
        self::assertCount(4, $rows);
    }

    public function testTheDownloadIsNamedAfterTheListAndTheDossier(): void
    {
        $response = TableExport::response($this->table(), ExportFormat::Xlsx, 'Consultation 401SCIE Menuiserie Vasseur SARL');

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=consultation-401scie-menuiserie-vasseur-sarl.xlsx', $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith('PK', (string) $response->getContent());
    }

    public function testARowHasOneCellPerColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->table()->add(['too', 'short']);
    }

    private function table(): ExportTable
    {
        $table = new ExportTable('401SCIE Scierie du Haut-Forez', [
            new Column('Date', ColumnKind::Day),
            new Column('Compte', ColumnKind::Code),
            new Column('Libellé'),
            new Column('Débit', ColumnKind::AmountCell),
            new Column('Crédit', ColumnKind::AmountCell),
            new Column('Solde', ColumnKind::Amount),
        ]);
        $table->add([new \DateTimeImmutable('2026-01-08'), '401000', 'Scierie; facture', Money::zero(), Money::cents(415_812), Money::cents(-415_812)]);
        $table->add([new \DateTimeImmutable('2026-01-20'), '00012', 'Règlement', Money::cents(240_000), null, Money::cents(-175_812)]);
        $table->add([new \DateTimeImmutable('2026-01-31'), '512000', '', null, Money::zero(), Money::zero()]);

        return $table;
    }
}
