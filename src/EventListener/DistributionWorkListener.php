<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Dossier\DistributionRepository;
use App\Service\Dossier\Distribution\DistributionQueue;
use App\Service\Dossier\Distribution\DistributionRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the dossiers of the distributions a request launched or relaunched, once its answer has
 * left (kernel.terminate), one copy after the other: the teacher reads the progress page while
 * they are made (design-ux.md § 4.2). A worker stopped in between leaves the rest to
 * app:distribution:advance.
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
        foreach ($this->queue->take() as $id) {
            $distribution = $this->distributions->find($id);
            if (null === $distribution) {
                continue;
            }
            foreach ($this->copies->findPendingIds($distribution) as $copy) {
                $this->runner->make($copy, $this->clock->now());
            }
        }
    }
}
