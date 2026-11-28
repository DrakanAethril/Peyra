<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Accounting\Fec\Import\FecImportQueue;
use App\Service\Accounting\Fec\Import\FecImportRunner;
use App\Service\Shared\Background\WorkBudget;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs passes of the FEC imports a request launched, relaunched or looked at, once its answer has
 * left (kernel.terminate) - the teacher reads the progress page while the template fills (step
 * 1.10b), and each time the page asks again it runs more. **A request runs passes for twelve
 * seconds at most** (WorkBudget): PHP's max_execution_time (30 s) counts the work after the answer
 * too, and a worker killed in the middle of a pass rolls it back for nothing. app:fec-import:advance, every
 * minute, takes up what is left whether a page is open or not.
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
        $budget = WorkBudget::startingNow($this->clock);
        foreach ($this->queue->take() as $id) {
            while ($budget->allowsMore() && $this->runner->pass($id, $this->clock->now())) {
            }
        }
    }
}
