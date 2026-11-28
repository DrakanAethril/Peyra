<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Repository\Dossier\DistributionCopyRepository;
use App\Service\Shared\Background\TransactionRetry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Makes a distribution's dossiers in the background - after the answer of the request that asked
 * (DistributionWorkListener), or by app:distribution:advance for what a stopped worker left -, one
 * copy per pass: each under its own lock (LOCK_DSN, seen by `php` and `worker` alike), read again
 * under it, so it is made once; a copy held by the other side is left to it.
 *
 * A transaction the database gave up (a deadlock with a copy made on the other side) is made again,
 * up to three times (TransactionRetry). A failure is logged at error level (it reaches the operator), said on the copy - « Relancer » -
 * and announced to the progress page like a success; the other copies are not touched.
 */
final readonly class DistributionRunner
{
    public function __construct(
        private LockFactory $locks,
        private EntityManagerInterface $entityManager,
        private TransactionRetry $retry,
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
                $this->retry->run(function () use ($copyId, $now): void {
                    $copy = $this->copies->find($copyId);
                    if (null !== $copy) {
                        $this->entityManager->refresh($copy);
                        $this->distributions->make($copy, $now);
                    }
                });
            } catch (\Throwable $exception) {
                $this->logger->error('Distribution copy {copy} not made: {message}', ['copy' => $copyId, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $this->retry->recover();
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
}
