<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Csv;

use App\Service\Shared\Csv\CsvRefused;
use App\Service\Shared\Csv\CsvText;
use PHPUnit\Framework\TestCase;

/** The CSV files exchanged with a spreadsheet: written for Excel in French, read from what people save. */
final class CsvTextTest extends TestCase
{
    public function testWritesUtf8WithABomSemicolonsAndQuotesOnlyWhenNeeded(): void
    {
        $text = CsvText::write([['numero', 'intitule'], ['601000', 'Achats ; matières'], ['164100', 'Emprunt "BP"']]);

        self::assertSame("\u{FEFF}numero;intitule\r\n601000;\"Achats ; matières\"\r\n164100;\"Emprunt \"\"BP\"\"\"\r\n", $text);
    }

    public function testReadsBackWhatItWrote(): void
    {
        $rows = CsvText::read(CsvText::write([['numero', 'intitule'], ['601000', 'Achats ; matières']]), 10);

        self::assertSame([['line' => 1, 'cells' => ['numero', 'intitule']], ['line' => 2, 'cells' => ['601000', 'Achats ; matières']]], $rows);
    }

    public function testGuessesTheSeparatorSkipsBlankLinesAndReadsWindows1252(): void
    {
        $rows = CsvText::read("601000,Achats\n\n  \n".mb_convert_encoding("602100,Matières consommables\r\n", 'Windows-1252', 'UTF-8'), 10);

        self::assertSame([['line' => 1, 'cells' => ['601000', 'Achats']], ['line' => 4, 'cells' => ['602100', 'Matières consommables']]], $rows);
        self::assertSame([['line' => 1, 'cells' => ['601000', 'Achats']]], CsvText::read("601000\tAchats\n", 10));
    }

    public function testRefusesABinaryFileAndTooManyRows(): void
    {
        try {
            CsvText::read("PK\x03\x04\0\0", 10);
            self::fail('Un fichier binaire est refusé.');
        } catch (CsvRefused $refused) {
            self::assertStringContainsString('n’est pas un fichier texte', $refused->getMessage());
        }
        $this->expectException(CsvRefused::class);
        $this->expectExceptionMessage('Ce fichier dépasse 2 lignes');
        CsvText::read("1;a\n2;b\n3;c\n", 2);
    }
}
