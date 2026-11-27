<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Scheduler\PeyraSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * Pins the list of recurring tasks (README of the plan, § 8, « Rien n'est supprimé
 * automatiquement »): adding one is a decision this test makes visible, and the place to check
 * that it deletes no dossier, no account and no enrolment.
 */
final class PeyraScheduleTest extends TestCase
{
    /** Each scheduled command, as `messenger:consume scheduler_default` will run it. */
    private const array EXPECTED = [];

    public function testTheScheduleRunsExactlyTheseCommands(): void
    {
        self::assertSame(self::EXPECTED, self::scheduledCommands());
    }

    public function testNoScheduledCommandDeletes(): void
    {
        $deleting = array_filter(self::scheduledCommands(), static fn (string $command): bool => 1 === preg_match('/\b(delete|purge|prune|remove|clean)\b/i', $command));

        self::assertSame([], $deleting, 'Rien n’est supprimé automatiquement.');
    }

    /** @return list<string> */
    private static function scheduledCommands(): array
    {
        $commands = [];
        foreach ((new PeyraSchedule(new ArrayAdapter()))->getSchedule()->getRecurringMessages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable('2026-11-27 12:00'));
            foreach ($recurring->getMessages($context) as $message) {
                $commands[] = $message instanceof RunCommandMessage ? $message->input : $message::class;
            }
        }

        return $commands;
    }
}
