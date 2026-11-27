<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Storage;

use App\Service\Shared\Storage\S3ClientFactory;
use PHPUnit\Framework\TestCase;

final class S3ClientFactoryTest extends TestCase
{
    public function testAnEndpointNamesAnS3CompatibleService(): void
    {
        $client = S3ClientFactory::create('eu-west-3', 'AKIAEXAMPLE', 'secret', 'http://minio:9000');

        self::assertSame('eu-west-3', $client->getRegion());
        self::assertSame('http://minio:9000', (string) $client->getEndpoint());
    }

    public function testWithoutEndpointItIsAws(): void
    {
        $client = S3ClientFactory::create('', '', '', '');

        self::assertSame('eu-west-3', $client->getRegion());
        self::assertStringContainsString('amazonaws.com', (string) $client->getEndpoint());
    }
}
