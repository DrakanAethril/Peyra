<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Dossier\Restore\RestoreQueue;
use App\Service\Dossier\Restore\RestoreRunner;
use App\Service\Shared\Background\WorkBudget;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Does the restore work a request asked for once its answer has left (kernel.terminate: FrankenPHP
 * and PHP-FPM send the answer first, `fastcgi_finish_request()`), so posing a point or going back
 * to one never makes a person wait (design-ux.md § 6.4) - while the request's WorkBudget lasts: a
 * whole class reset or given a point is thirty dossiers. The waiting pages queue their return or
 * their points again when they ask; what is left, app:restore-points:advance finishes.
 */
final readonly class RestoreWorkListener
{
    public function __construct(private RestoreQueue $queue, private RestoreRunner $runner, private ClockInterface $clock)
    {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $budget = WorkBudget::startingNow($this->clock);
        $work = $this->queue->take();
        foreach ($work['points'] as $point) {
            if (!$budget->allowsMore()) {
                return;
            }
            $this->runner->preparePoint($point, $this->clock->now());
        }
        foreach ($work['restorations'] as $restoration) {
            if (!$budget->allowsMore()) {
                return;
            }
            $this->runner->restore($restoration, $this->clock->now());
        }
    }
}
