<?php

declare(strict_types=1);

namespace App\Tests\Service\Dossier\Restore;

use App\Service\Dossier\Restore\RestorePointRules;
use PHPUnit\Framework\TestCase;

final class RestorePointRulesTest extends TestCase
{
    public function testTheNameOfferedIsTheOneOfTheDesign(): void
    {
        self::assertSame('Avant validation du 30/11/2026', RestorePointRules::defaultName(new \DateTimeImmutable('2026-11-30')));
    }

    public function testThePointOfTheStateLeftIsNamedAfterThePointGoneBackTo(): void
    {
        self::assertSame('Avant le retour à « Avant clôture »', RestorePointRules::safetyName('Avant clôture'));
        $long = RestorePointRules::safetyName(str_repeat('é', 80));
        self::assertSame(80, mb_strlen($long));
        self::assertStringEndsWith('… »', $long);
    }

    public function testANameIsOneToEightyCharacters(): void
    {
        self::assertSame('Donnez un nom au point de restauration.', RestorePointRules::nameFault('   '));
        self::assertNull(RestorePointRules::nameFault(str_repeat('a', 80)));
        self::assertSame('Le nom tient en 80 caractères au plus.', RestorePointRules::nameFault(str_repeat('a', 81)));
    }

    public function testTenPointsAtMost(): void
    {
        self::assertFalse(RestorePointRules::limitReached(9));
        self::assertTrue(RestorePointRules::limitReached(10));
    }
}
