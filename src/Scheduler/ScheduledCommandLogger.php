<?php

declare(strict_types=1);

namespace App\Scheduler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The journal of the scheduled tasks (App\Scheduler\PeyraSchedule): one line per run of a command,
 * in the worker's log (`docker compose logs worker`), taken from Formatool (same author and
 * licence).
 *
 * A task that exits 0 is a notice. One that exits non-zero is an error carrying the tail of its
 * output: the exit code says that something failed, the output what. A command that throws is
 * Messenger's to log, at critical level.
 */
#[AsEventListener]
final readonly class ScheduledCommandLogger
{
    /** Enough to carry an error message and its context, not a whole table of results. */
    private const int OUTPUT_TAIL = 1500;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        if (!$envelope->getMessage() instanceof RunCommandMessage) {
            return;
        }

        foreach ($envelope->all(HandledStamp::class) as $stamp) {
            $context = $stamp->getResult();
            if (!$context instanceof RunCommandContext) {
                continue;
            }

            $values = [
                'command' => $context->message->input,
                'exitCode' => $context->exitCode,
                'output' => self::tail($context->output),
            ];
            if (0 === $context->exitCode) {
                $this->logger->notice('Scheduled task "{command}" exited with code {exitCode}.', $values);

                continue;
            }
            $this->logger->error('Scheduled task "{command}" exited with code {exitCode}: {output}', $values);
        }
    }

    private static function tail(string $output): string
    {
        // SymfonyStyle pads its blocks with blank lines and trailing spaces; none of it says anything.
        $lines = array_filter(array_map(rtrim(...), explode("\n", $output)), static fn (string $line): bool => '' !== trim($line));
        $text = implode("\n", $lines);

        return mb_strlen($text) > self::OUTPUT_TAIL ? '…'.mb_substr($text, -self::OUTPUT_TAIL) : $text;
    }
}
