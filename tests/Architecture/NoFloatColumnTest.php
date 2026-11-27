<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\SmallFloatType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Money is integer cents, rates and quantities exact decimals (README of the plan, § 8): no column
 * of the schema may be stored as a binary floating-point number. PHPStan cannot see a column type,
 * so this reads the ORM metadata of every entity, embedded fields included, and asks the platform
 * what SQL each field's type declares - which also catches a custom type built on DOUBLE.
 */
final class NoFloatColumnTest extends KernelTestCase
{
    public function testNoEntityStoresAFloatingPointColumn(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $platform = $entityManager->getConnection()->getDatabasePlatform();

        $offenders = [];
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $offenders = [...$offenders, ...self::floatingPointFields($metadata, $platform)];
        }

        self::assertSame([], $offenders, 'Colonnes à virgule flottante : utiliser des centimes entiers (Money) ou un décimal exact.');
    }

    public function testTheCheckRecognisesAFloatColumn(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = new ClassMetadata(\stdClass::class);
        $metadata->mapField(['fieldName' => 'amount', 'type' => 'float']);
        $metadata->mapField(['fieldName' => 'cents', 'type' => 'bigint']);
        $metadata->mapField(['fieldName' => 'rate', 'type' => 'decimal', 'precision' => 9, 'scale' => 6]);

        self::assertSame(
            ['stdClass::$amount (float)'],
            self::floatingPointFields($metadata, $entityManager->getConnection()->getDatabasePlatform()),
        );
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return list<string>
     */
    private static function floatingPointFields(ClassMetadata $metadata, AbstractPlatform $platform): array
    {
        $offenders = [];
        foreach ($metadata->fieldMappings as $field => $mapping) {
            $type = Type::getType($mapping->type);
            if ($type instanceof FloatType || $type instanceof SmallFloatType || self::declaresFloatingPoint($type, $mapping, $platform)) {
                $offenders[] = \sprintf('%s::$%s (%s)', $metadata->getName(), $field, $mapping->type);
            }
        }

        return $offenders;
    }

    /**
     * A custom type is judged by the SQL it declares. The defaults only stand in for options the
     * platform insists on (a VARCHAR's length, a DECIMAL's precision) and never change the type.
     */
    private static function declaresFloatingPoint(Type $type, FieldMapping $mapping, AbstractPlatform $platform): bool
    {
        $declaration = $type->getSQLDeclaration([
            'length' => $mapping->length ?? 255,
            'precision' => $mapping->precision ?? 10,
            'scale' => $mapping->scale ?? 0,
        ], $platform);

        return 1 === preg_match('/\b(FLOAT|DOUBLE|REAL)\b/i', $declaration);
    }
}
