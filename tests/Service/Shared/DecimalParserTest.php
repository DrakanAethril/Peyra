<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalParserTest extends TestCase
{
    /** @return iterable<string, array{string, string}> typed => exact decimal */
    public static function readable(): iterable
    {
        yield 'comma' => ['6,90', '6.90'];
        yield 'point' => ['0.3193', '0.3193'];
        yield 'whole' => ['48060', '48060'];
        yield 'thousands with spaces' => ['48 060', '48060'];
        yield 'narrow no-break space, as Peyra prints it' => ["1\u{202F}823,03", '1823.03'];
        yield 'both separators, comma last' => ['1.234,5678', '1234.5678'];
        yield 'both separators, point last' => ['1,234.5', '1234.5'];
        yield 'repeated point separates thousands' => ['1.234.567', '1234567'];
        yield 'unicode minus' => ["\u{2212}2,5", '-2.5'];
        yield 'plus sign' => ['+0,5', '0.5'];
        yield 'percent sign ignored' => ['13 %', '13'];
        yield 'euro sign ignored' => ['40 €', '40'];
        yield 'leading decimal' => [',75', '0.75'];
        yield 'many decimals kept' => ['0,000125', '0.000125'];
    }

    #[DataProvider('readable')]
    public function testReadsWhatAPersonTypes(string $typed, string $expected): void
    {
        self::assertSame($expected, DecimalParser::parse($typed)->toString());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unreadable(): iterable
    {
        yield 'empty' => ['  ', 'Saisissez un nombre.'];
        yield 'letters' => ['douze', 'Ce nombre n’est pas lisible. Écrivez par exemple 6,90.'];
        yield 'badly grouped thousands' => ['12.34.5', 'Les milliers de ce nombre sont mal séparés.'];
        yield 'two signs' => ['--3', 'Ce nombre n’est pas lisible. Écrivez par exemple 6,90.'];
        yield 'too long' => [str_repeat('9', 30), 'Ce nombre est trop long.'];
    }

    #[DataProvider('unreadable')]
    public function testRefusesWithASentence(string $typed, string $sentence): void
    {
        $this->expectException(DecimalParseError::class);
        $this->expectExceptionMessage($sentence);
        DecimalParser::parse($typed);
    }
}
