<?php

declare(strict_types=1);

namespace App\Tests\Controller\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testAnswersWithoutSignInOrDatabase(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }
}
