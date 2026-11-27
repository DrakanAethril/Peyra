<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Service\Identity\FormatoolLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The way back to Formatool and the forgotten password, from the two variables of the environment. */
final class FormatoolLinksTest extends TestCase
{
    public function testNothingConfiguredDrawsNoLink(): void
    {
        $links = new FormatoolLinks('', '');

        self::assertNull($links->home());
        self::assertNull($links->passwordHelp());
    }

    public function testTheForgottenPasswordIsFormatoolsSignInByMailedLink(): void
    {
        $links = new FormatoolLinks('https://formatool.example.org/', '');

        self::assertSame('https://formatool.example.org/', $links->home());
        self::assertSame('https://formatool.example.org/login/magic-link', $links->passwordHelp());
    }

    public function testAnExplicitProcedureOverridesFormatools(): void
    {
        $links = new FormatoolLinks('https://formatool.example.org', ' https://aide.example.org/mot-de-passe ');

        self::assertSame('https://formatool.example.org/', $links->home());
        self::assertSame('https://aide.example.org/mot-de-passe', $links->passwordHelp());
        self::assertSame('https://aide.example.org/mot-de-passe', new FormatoolLinks('', 'https://aide.example.org/mot-de-passe')->passwordHelp());
    }

    #[DataProvider('notAnAddress')]
    public function testAnythingButAnHttpAddressIsNoLink(string $value): void
    {
        $links = new FormatoolLinks($value, $value);

        self::assertNull($links->home());
        self::assertNull($links->passwordHelp());
    }

    /** @return iterable<string, array{string}> */
    public static function notAnAddress(): iterable
    {
        yield 'blank' => ['   '];
        yield 'a host alone' => ['formatool.example.org'];
        yield 'a script' => ['javascript:alert(1)'];
        yield 'another scheme' => ['ftp://formatool.example.org'];
        yield 'no host' => ['https://'];
    }
}
