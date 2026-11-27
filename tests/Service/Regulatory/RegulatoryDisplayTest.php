<?php

declare(strict_types=1);

namespace App\Tests\Service\Regulatory;

use App\Service\Regulatory\RegulatoryDisplay;
use PHPUnit\Framework\TestCase;

final class RegulatoryDisplayTest extends TestCase
{
    private const string T = "\u{202F}";
    private const string N = "\u{00A0}";

    public function testNumbersArePrintedInFrenchWithTheirUnit(): void
    {
        self::assertSame('12,02'.self::N.'€/heure', RegulatoryDisplay::leaf('12.02', 'EUR/heure'));
        self::assertSame('1'.self::T.'823,03'.self::N.'€/mois', RegulatoryDisplay::leaf('1823.03', 'EUR/mois'));
        self::assertSame('6,9'.self::N.'%', RegulatoryDisplay::leaf('6.9', 'percent'));
        self::assertSame('50'.self::N.'% du SMIC', RegulatoryDisplay::leaf('50', 'percent of SMIC'));
        self::assertSame('40'.self::N.'€', RegulatoryDisplay::leaf('40', 'EUR'));
        self::assertSame('12,70'.self::N.'€/heure', RegulatoryDisplay::leaf('12.7', 'EUR/heure'), 'Cents come in pairs.');
        self::assertSame('0,175'.self::N.'€', RegulatoryDisplay::leaf('0.175', 'EUR'));
        self::assertSame('0,3193', RegulatoryDisplay::leaf('0.3193', 'coefficient'));
        self::assertSame('1'.self::T.'620', RegulatoryDisplay::leaf('1620', 'percent (bornes en EUR, borne basse incluse, borne haute exclue)'), 'A unit explained in words puts no sign on the bounds.');
    }

    public function testOtherLeaves(): void
    {
        self::assertSame('01/01/2027', RegulatoryDisplay::leaf('2027-01-01', 'date'));
        self::assertSame('2027-02-31', RegulatoryDisplay::leaf('2027-02-31', 'date'), 'Not a day: shown as written.');
        self::assertSame('Oui', RegulatoryDisplay::leaf(true, 'text'));
        self::assertSame('Non', RegulatoryDisplay::leaf(false, 'text'));
        self::assertSame('—', RegulatoryDisplay::leaf(null, 'EUR'));
        self::assertSame('brut', RegulatoryDisplay::leaf('brut', 'percent'));
    }

    public function testWhatAPersonEdits(): void
    {
        self::assertSame('1'.self::T.'823,03', RegulatoryDisplay::editable('1823.03'));
        self::assertSame('brut', RegulatoryDisplay::editable('brut'));
        self::assertSame('1', RegulatoryDisplay::editable(true));
        self::assertSame('', RegulatoryDisplay::editable(null));
    }

    public function testUnitNames(): void
    {
        self::assertSame('Pourcentage', RegulatoryDisplay::unitName('percent'));
        self::assertSame('Euros par mois', RegulatoryDisplay::unitName('EUR/mois'));
        self::assertSame('EUR/semaine', RegulatoryDisplay::unitName('EUR/semaine'), 'A unit the files do not use yet is shown as written.');
        self::assertSame('€/semaine', RegulatoryDisplay::suffix('EUR/semaine'));
        self::assertSame('', RegulatoryDisplay::suffix('text'));
    }
}
