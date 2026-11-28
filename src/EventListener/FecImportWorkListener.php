<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Accounting\Fec\Import\FecImportQueue;
use App\Service\Accounting\Fec\Import\FecImportRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs the passes of the FEC imports a request launched or relaunched, once its answer has left
 * (kernel.terminate): the teacher reads the progress page while the template fills (step 1.10b).
 * A worker stopped in between leaves the rest to app:fec-import:advance.
 */
final readonly class FecImportWorkListener
{
    public function __construct(
        private FecImportQueue $queue,
        private FecImportRunner $runner,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        foreach ($this->queue->take() as $id) {
            while ($this->runner->pass($id, $this->clock->now())) {
            }
        }
    }
}
