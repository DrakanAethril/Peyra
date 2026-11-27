<?php

declare(strict_types=1);

namespace App\Tests\Service\Accounting\Chart;

use App\Service\Accounting\Chart\ChartImport;
use App\Service\Shared\Csv\CsvRefused;
use PHPUnit\Framework\TestCase;

/** How an imported plan's lines are read, each on its own (ChartImport::parse, a pure function). */
final class ChartImportParseTest extends TestCase
{
    public function testAHeaderNamesTheColumnsInAnyOrder(): void
    {
        $rows = ChartImport::parse("Intitulé;Numéro;Collectif\nAchats de vitrages;601300;non\nClients douteux;416000;oui\n");

        self::assertSame([
            ['line' => 2, 'number' => '601300', 'label' => 'Achats de vitrages', 'lettrable' => null, 'collective' => false, 'refusal' => null],
            ['line' => 3, 'number' => '416000', 'label' => 'Clients douteux', 'lettrable' => null, 'collective' => true, 'refusal' => null],
        ], $rows);
    }

    public function testWithoutAHeaderTheColumnsAreNumberLabelLettrableCollective(): void
    {
        $rows = ChartImport::parse("601300;Achats de vitrages;x;\n419100;Avances reçues;oui;O\n");

        self::assertSame([true, null], [$rows[0]['lettrable'], $rows[0]['collective']]);
        self::assertSame([true, true], [$rows[1]['lettrable'], $rows[1]['collective']]);
    }

    public function testEachFaultyLineSaysWhy(): void
    {
        $rows = ChartImport::parse("numero;intitule;lettrable\n601;Achats;\n601300;;\n401SCIE;Scierie;\n601400;Quincaillerie;peut-être\n6014 00;Quincaillerie;non\n");

        self::assertSame([
            'Un numéro de compte compte de 6 à 8 chiffres (601000, 6011000…).',
            'Il manque l’intitulé du compte.',
            'Un numéro de compte ne s’écrit qu’avec des chiffres ; un compte de tiers comme 401SCIE est le code d’un tiers.',
            'Les colonnes « lettrable » et « collectif » valent oui ou non.',
            null,
        ], array_column($rows, 'refusal'));
        self::assertSame('601400', $rows[4]['number']);
    }

    public function testAHeaderWithoutNumberAndLabelRefusesTheFile(): void
    {
        $this->expectException(CsvRefused::class);
        $this->expectExceptionMessage('La première ligne doit nommer les colonnes « numero » et « intitule »');
        ChartImport::parse("compte général;désignation\n601000;Achats\n");
    }
}
