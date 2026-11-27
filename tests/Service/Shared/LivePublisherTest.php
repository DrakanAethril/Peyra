<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared;

use App\Service\Shared\LivePublisher;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

final class LivePublisherTest extends KernelTestCase
{
    public function testAnUpdateIsPrivateJsonByDefault(): void
    {
        $last = null;
        $hub = new MockHub('http://php/.well-known/mercure', new StaticTokenProvider('t'), static function (Update $update) use (&$last): string {
            $last = $update;

            return 'urn:uuid:1';
        });
        $published = (new LivePublisher($hub, new NullLogger()))->publish('peyra/class/12', ['entries' => 3, 'student' => 'Inès Morel']);

        self::assertTrue($published);
        self::assertInstanceOf(Update::class, $last);
        self::assertSame(['peyra/class/12'], $last->getTopics());
        self::assertTrue($last->isPrivate());
        self::assertSame('{"entries":3,"student":"Inès Morel"}', $last->getData());
    }

    public function testAFailingHubIsLoggedAndNeverThrown(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = (\is_string($level) ? $level : '').' '.$message;
            }
        };
        $hub = new MockHub('http://php/.well-known/mercure', new StaticTokenProvider('t'), static fn (Update $update): string => throw new \RuntimeException('hub down'));

        self::assertFalse((new LivePublisher($hub, $logger))->publish(['peyra/a', 'peyra/b'], ['x' => 1]));
        self::assertSame(['warning Live update not published on {topics}: {message}'], $logger->lines);
    }

    public function testTheHubOfTheStackTakesAnUpdate(): void
    {
        self::assertTrue(self::getContainer()->get(LivePublisher::class)->publish('peyra/test/live-publisher', ['ok' => true]), 'The hub of the container accepts the publisher key.');
    }
}
