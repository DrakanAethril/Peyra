<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Dossier\DistributionRepository;
use App\Service\Dossier\Distribution\DistributionQueue;
use App\Service\Dossier\Distribution\DistributionRunner;
use App\Service\Shared\Background\WorkBudget;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the dossiers of the distributions a request launched, relaunched or looked at, once its
 * answer has left (kernel.terminate), one copy after the other while the request's WorkBudget
 * lasts: the teacher reads the progress page while they are made (design-ux.md § 4.2), and each
 * time the page asks again a few more are made. What is left - a class of thirty-five, a worker
 * stopped in between - app:distribution:advance finishes.
 */
final readonly class DistributionWorkListener
{
    public function __construct(
        private DistributionQueue $queue,
        private DistributionRepository $distributions,
        private DistributionCopyRepository $copies,
        private DistributionRunner $runner,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        $budget = WorkBudget::startingNow($this->clock);
        foreach ($this->queue->take() as $id) {
            $distribution = $this->distributions->find($id);
            if (null === $distribution) {
                continue;
            }
            foreach ($this->copies->findPendingIds($distribution) as $copy) {
                if (!$budget->allowsMore()) {
                    return;
                }
                $this->runner->make($copy, $this->clock->now());
            }
        }
    }
}
