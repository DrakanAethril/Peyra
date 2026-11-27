<?php

declare(strict_types=1);

namespace App\Service\Shared;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes a live update to the Mercure hub bundled in the container (the class's live follow-up,
 * step 2.10). A live update is a comfort, never a condition: whatever the hub does - down, slow
 * (three seconds at most, config/services.yaml), refusing the key - the failure is logged as a
 * warning and the caller goes on; the screens catch up at their next request.
 *
 * Updates are private by default: what they carry is students' work, read by subscribers holding a
 * token for the topic (the `subscriber` hub mints them). Topics are written « peyra/<area>/<id> ».
 */
final readonly class LivePublisher
{
    public function __construct(
        private HubInterface $defaultHub,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|list<string>  $topics
     * @param array<string, mixed> $data   encoded as JSON
     *
     * @return bool whether the hub took the update
     */
    public function publish(string|array $topics, array $data, bool $private = true): bool
    {
        try {
            $this->defaultHub->publish(new Update($topics, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $private));

            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('Live update not published on {topics}: {message}', [
                'topics' => implode(', ', (array) $topics),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
