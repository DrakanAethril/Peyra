<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Repository\Dossier\DistributionCopyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Makes a distribution's dossiers in the background - after the answer of the request that asked
 * (DistributionWorkListener), or by app:distribution:advance for what a stopped worker left -, one
 * copy per pass: each under its own lock (LOCK_DSN, seen by `php` and `worker` alike), read again
 * under it, so it is made once; a copy held by the other side is left to it.
 *
 * A failure is logged at error level (it reaches the operator), said on the copy - « Relancer » -
 * and announced to the progress page like a success; the other copies are not touched.
 */
final readonly class DistributionRunner
{
    public function __construct(
        private LockFactory $locks,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
        private DistributionCopyRepository $copies,
        private Distributions $distributions,
        private DistributionLive $live,
        private LoggerInterface $logger,
    ) {
    }

    /** Makes one copy; false when there was nothing to do here (made, failed, or held by the other side). */
    public function make(int $copyId, \DateTimeImmutable $now): bool
    {
        $lock = $this->locks->createLock(Distributions::lockName($copyId), 600.0);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $copy = $this->copies->find($copyId);
            if (null === $copy) {
                return false;
            }
            $this->entityManager->refresh($copy);
            if (!$copy->getState()->isPending()) {
                return false;
            }
            try {
                $this->distributions->make($copy, $now);
            } catch (\Throwable $exception) {
                $this->logger->error('Distribution copy {copy} not made: {message}', ['copy' => $copyId, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $this->recover();
                $copy = $this->copies->find($copyId);
                if (null === $copy) {
                    return true;
                }
                $this->distributions->fail($copy, $exception instanceof DistributionRefused ? $exception->getMessage() : Distributions::COPY_FAILURE, $now);
            }
            $this->live->announce($copy->getDistribution());

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * After a failure: an entity manager that flushes again, nothing stale in it (the dossier
     * persisted in the rolled-back transaction above all).
     */
    private function recover(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        } else {
            $this->registry->resetManager();
        }
    }
}
