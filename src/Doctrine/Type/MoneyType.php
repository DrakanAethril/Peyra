<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Service\Shared\Money;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * `#[ORM\Column(type: 'money')]`: a Money in a BIGINT column of cents. The column is an integer,
 * so NoFloatColumnTest passes it and a sum in SQL stays exact.
 */
final class MoneyType extends Type
{
    public const string NAME = 'money';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBigIntTypeDeclarationSQL($column);
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof Money) {
            return $value->cents;
        }

        throw InvalidType::new($value, self::NAME, ['null', Money::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Money
    {
        if (null === $value) {
            return null;
        }
        if (\is_int($value)) {
            return Money::cents($value);
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            $cents = (int) $value;
            if ((string) $cents === ltrim($value, '+')) {
                return Money::cents($cents);
            }
        }

        throw ValueNotConvertible::new($value, self::NAME);
    }
}
