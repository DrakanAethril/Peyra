<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\Type\MoneyType;
use App\Service\Shared\Money;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\TestCase;

final class MoneyTypeTest extends TestCase
{
    private MoneyType $type;
    private MariaDBPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new MoneyType();
        $this->platform = new MariaDBPlatform();
    }

    public function testTheColumnIsABigInt(): void
    {
        self::assertSame('BIGINT', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testRoundTrip(): void
    {
        self::assertSame(-13800, $this->type->convertToDatabaseValue(Money::cents(-13800), $this->platform));
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertSame(262200, $this->type->convertToPHPValue(262200, $this->platform)?->cents);
        self::assertSame(-5, $this->type->convertToPHPValue('-5', $this->platform)?->cents, 'The driver may hand a BIGINT as a string.');
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testAnIntegerIsNotAcceptedAsMoney(): void
    {
        $this->expectException(InvalidType::class);
        $this->type->convertToDatabaseValue(12, $this->platform);
    }

    public function testADecimalStringIsRefused(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue('12.50', $this->platform);
    }

    public function testAValueBeyondAnIntegerIsRefused(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue('99999999999999999999', $this->platform);
    }
}
