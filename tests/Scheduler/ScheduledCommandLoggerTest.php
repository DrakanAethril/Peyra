<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Scheduler\ScheduledCommandLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ScheduledCommandLoggerTest extends TestCase
{
    public function testOneLinePerRunAndTheOutputOfAFailure(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<array-key, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = [\is_string($level) ? $level : '', (string) $message, $context];
            }
        };
        $listener = new ScheduledCommandLogger($logger);

        $listener($this->handled('app:demo:task', 0, "  \nFait.\n\n"));
        $listener($this->handled('app:demo:task --force', 1, "\n [ERROR] Gotenberg ne répond pas.   \n\n"));
        $listener(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'scheduler_default'));

        self::assertCount(2, $logger->lines);
        self::assertSame('notice', $logger->lines[0][0]);
        self::assertSame('app:demo:task', $logger->lines[0][2]['command']);
        self::assertSame('error', $logger->lines[1][0]);
        self::assertSame(1, $logger->lines[1][2]['exitCode']);
        self::assertSame(' [ERROR] Gotenberg ne répond pas.', $logger->lines[1][2]['output']);
    }

    private function handled(string $input, int $exitCode, string $output): WorkerMessageHandledEvent
    {
        $message = new RunCommandMessage($input);
        $envelope = (new Envelope($message))->with(new HandledStamp(new RunCommandContext($message, $exitCode, $output), 'console.messenger.execute_command_handler'));

        return new WorkerMessageHandledEvent($envelope, 'scheduler_default');
    }
}
