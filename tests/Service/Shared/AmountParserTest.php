<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\AmountParseError;
use App\Service\Shared\AmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountParserTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function readable(): iterable
    {
        yield 'french with a normal space' => ['1 234,56', 123456];
        yield 'french with a narrow no-break space' => ["1\u{202F}234,56", 123456];
        yield 'french with a no-break space and euro' => ["2\u{00A0}622,00\u{00A0}€", 262200];
        yield 'point as decimal' => ['1234.56', 123456];
        yield 'whole euros' => ['1234', 123400];
        yield 'one decimal' => ['12,5', 1250];
        yield 'decimal without integer part' => [',50', 50];
        yield 'leading zeros' => ['007,10', 710];
        yield 'minus' => ['-138', -13800];
        yield 'typographic minus' => ["\u{2212}\u{00A0}138,00", -13800];
        yield 'plus' => ['+15', 1500];
        yield 'german thousands' => ['1.234,56', 123456];
        yield 'english thousands' => ['1,234.56', 123456];
        yield 'repeated thousands' => ['1.234.567', 123456700];
        yield 'zero' => ['0', 0];
        yield 'surrounding spaces' => ['  42  ', 4200];
    }

    #[DataProvider('readable')]
    public function testReadsWhatAPersonTypes(string $input, int $cents): void
    {
        self::assertSame($cents, AmountParser::parse($input)->cents);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unreadable(): iterable
    {
        yield 'empty' => ['', 'Saisissez un montant.'];
        yield 'blank' => ["  \u{00A0}", 'Saisissez un montant.'];
        yield 'letters' => ['douze', 'Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.'];
        yield 'a sign alone' => ['-', 'Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.'];
        yield 'arithmetic' => ['2760-138', 'Ce montant n’est pas lisible. Écrivez par exemple 1 234,56.'];
        yield 'three decimals' => ['1,234', 'Un montant a deux décimales au plus.'];
        yield 'bad groups' => ['12.34,56', 'Les milliers de ce montant sont mal séparés.'];
        yield 'bad repeated groups' => ['1.23.456', 'Les milliers de ce montant sont mal séparés.'];
        yield 'too large' => ['1234567890123', 'Ce montant est trop grand.'];
    }

    #[DataProvider('unreadable')]
    public function testRefusesWithASentence(string $input, string $message): void
    {
        try {
            AmountParser::parse($input);
            self::fail('Expected a refusal for '.$input);
        } catch (AmountParseError $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    public function testAnEmptyOptionalFieldIsNotZero(): void
    {
        self::assertNull(AmountParser::parseOptional(''));
        self::assertNull(AmountParser::parseOptional(" \u{202F}"));
        self::assertSame(0, AmountParser::parseOptional('0')?->cents);
    }
}
