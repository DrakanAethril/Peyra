<?php

declare(strict_types=1);

namespace App\Controller\Shared;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public liveness probe: answers as soon as PHP serves requests, without touching the database,
 * so that a monitor can tell « the application is up » from « its database is down ».
 */
final class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET', 'HEAD'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse(['status' => 'ok']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
