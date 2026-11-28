<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\ReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The way back of « du solde au détail, et retour »: a path of the same dossier, nothing else. */
final class ReturnPathTest extends TestCase
{
    public function testAPathOfTheSameDossierIsKept(): void
    {
        self::assertSame('/d/12/accounting/consultation?account=401SCIE&from=2026-01-01', ReturnPath::of(' /d/12/accounting/consultation?account=401SCIE&from=2026-01-01 ', 12));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'another dossier' => ['/d/13/accounting/consultation'];
        yield 'a prefix of another id' => ['/d/123/accounting'];
        yield 'another host' => ['https://example.org/d/12/'];
        yield 'protocol-relative' => ['//example.org/d/12/'];
        yield 'double slash further' => ['/d/12//example.org'];
        yield 'backslash' => ['/d/12/\\example.org'];
        yield 'control character' => ["/d/12/accounting\r\nLocation: x"];
        yield 'outside a dossier' => ['/teacher/admin'];
        yield 'too long' => ['/d/12/'.str_repeat('a', 1000)];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(string $typed): void
    {
        self::assertNull(ReturnPath::of($typed, 12));
    }

    public function testTheAddressHereLeavesItsOwnWayBackOut(): void
    {
        self::assertSame('/d/12/accounting/consultation?account=401SCIE&page=2', ReturnPath::here('/d/12/accounting/consultation', 'account=401SCIE&return=%2Fd%2F12%2Faccounting%2Fentry&page=2'));
        self::assertSame('/d/12/accounting/brouillard', ReturnPath::here('/d/12/accounting/brouillard', ''));
    }
}
