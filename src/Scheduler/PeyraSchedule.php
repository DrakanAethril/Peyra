<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Every recurring task of Peyra, in one place, consumed by the `worker` service
 * (`messenger:consume scheduler_default`). Nothing scheduled ever deletes a dossier, an account or
 * an enrolment.
 */
#[AsSchedule('default')]
final class PeyraSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // A task whose time fell while the worker restarted runs when it comes back, once.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
