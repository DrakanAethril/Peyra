<?php

declare(strict_types=1);

namespace App\Tests\Service\Identity;

use App\Service\Identity\StableId;
use PHPUnit\Framework\TestCase;

final class StableIdTest extends TestCase
{
    public function testTheConfiguredAttributeComesFirst(): void
    {
        self::assertSame('entryUUID:4960fd9e-7ebd-1041-913b-05abae13d4dc', StableId::of([
            'uid' => ['ines.morel'],
            'uidNumber' => ['40104'],
            'entryUUID' => ['4960fd9e-7ebd-1041-913b-05abae13d4dc'],
        ], 'entryUUID'));
    }

    public function testUidNumberWhenTheAttributeIsAbsent(): void
    {
        self::assertSame('uidNumber:40104', StableId::of(['uidnumber' => ['40104']], 'objectGUID'));
    }

    public function testNothingWithoutEither(): void
    {
        self::assertNull(StableId::of(['uid' => ['x']], 'objectGUID'));
        self::assertNull(StableId::of(['objectGUID' => ['']], 'objectGUID'));
    }

    public function testABinaryObjectGuidIsWrittenInItsTextForm(): void
    {
        // 00112233-4455-6677-8899-aabbccddeeff is stored with its first three fields reversed.
        $bytes = hex2bin('33221100554477668899aabbccddeeff');
        self::assertIsString($bytes);

        self::assertSame('objectGUID:00112233-4455-6677-8899-aabbccddeeff', StableId::of(['objectGUID' => [$bytes]], 'objectGUID'));
    }

    public function testATextGuidIsKept(): void
    {
        self::assertSame('00112233-4455-6677-8899-aabbccddeeff', StableId::guid('00112233-4455-6677-8899-AABBCCDDEEFF'));
    }
}
