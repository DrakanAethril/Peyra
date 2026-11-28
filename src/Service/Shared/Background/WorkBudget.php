<?php

declare(strict_types=1);

namespace App\Service\Shared\Background;

use Symfony\Component\Clock\ClockInterface;

/**
 * How long a request goes on working after its answer has left (kernel.terminate). PHP's
 * max_execution_time (30 s) counts that work too: a worker killed in the middle of a copy rolls it
 * back for nothing. So a request does units of work while its budget lasts - the copies of a
 * distribution, the returns of a whole class, the passes of a FEC import - and leaves the rest to
 * the scheduled commands (every minute) and to the progress pages that ask again (step 1.10c).
 */
final readonly class WorkBudget
{
    public const int SECONDS = 12;

    private function __construct(private ClockInterface $clock, private \DateTimeImmutable $until)
    {
    }

    public static function startingNow(ClockInterface $clock, int $seconds = self::SECONDS): self
    {
        return new self($clock, $clock->now()->modify('+'.$seconds.' seconds'));
    }

    /** Whether another unit of work may start now. */
    public function allowsMore(): bool
    {
        return $this->clock->now() < $this->until;
    }
}
