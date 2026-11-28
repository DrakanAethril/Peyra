<?php

declare(strict_types=1);

namespace App\Service\Dossier\Restore;

use App\Entity\Dossier\Dossier;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Dossier\DossierLive;
use App\Service\Shared\Background\TransactionRetry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs the restore work in the background - after the answer of the request that asked for it
 * (RestoreWorkListener), or by app:restore-points:advance for what a stopped worker left -, one
 * dossier at a time: the work on a dossier holds its lock (LOCK_DSN, seen by `php` and `worker`
 * alike), and a work already taken by the other side is left to it. Each piece of work is read
 * again under the lock, so it is done once.
 *
 * A transaction the database gave up (a deadlock with the work on another dossier) is made again,
 * up to three times (TransactionRetry). A failure is logged at error level (it reaches the
 * operator), said on the point or the return, and announced to the dossier's tabs like a success:
 * the dossier holds what it held.
 */
final readonly class RestoreRunner
{
    public function __construct(
        private LockFactory $locks,
        private EntityManagerInterface $entityManager,
        private TransactionRetry $retry,
        private RestorePointRepository $points,
        private DossierRestorationRepository $restorations,
        private RestorePoints $restorePoints,
        private Restorations $restorer,
        private DossierLive $live,
        private LoggerInterface $logger,
    ) {
    }

    /** The lock every piece of restore work on a dossier takes (the distribution's copies will too). */
    public static function lockName(int $dossierId): string
    {
        return 'dossier-copy-'.$dossierId;
    }

    /** Makes a point's copy; false when there was nothing to do here (done, or held by the other side). */
    public function preparePoint(int $pointId, \DateTimeImmutable $now): bool
    {
        $point = $this->points->find($pointId);
        if (null === $point || !$point->isPreparing()) {
            return false;
        }
        $dossier = $point->getDossier();

        return $this->underLock($dossier, function () use ($pointId, $now): bool {
            $point = $this->points->find($pointId);
            if (null === $point) {
                return false;
            }
            $this->entityManager->refresh($point);
            if (!$point->isPreparing()) {
                return false;
            }
            try {
                $this->retry->run(function () use ($pointId, $now): void {
                    $point = $this->points->find($pointId);
                    if (null !== $point) {
                        $this->entityManager->refresh($point);
                        $this->restorePoints->prepare($point, $now);
                    }
                });
            } catch (\Throwable $exception) {
                $this->logger->error('Restore point {point} not copied: {message}', ['point' => $pointId, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $this->retry->recover();
                $point = $this->points->find($pointId);
                if (null === $point) {
                    return true;
                }
                $this->restorePoints->fail($point, $now);
            }
            $this->live->announce($point->getDossier(), DossierLive::POINTS);

            return true;
        });
    }

    /** Takes a dossier back to a point; false when there was nothing to do here. */
    public function restore(int $restorationId, \DateTimeImmutable $now): bool
    {
        $restoration = $this->restorations->find($restorationId);
        if (null === $restoration || !$restoration->isRunning()) {
            return false;
        }
        $dossier = $restoration->getDossier();

        return $this->underLock($dossier, function () use ($restorationId, $now): bool {
            $restoration = $this->restorations->find($restorationId);
            if (null === $restoration) {
                return false;
            }
            $this->entityManager->refresh($restoration);
            if (!$restoration->isRunning()) {
                return false;
            }
            try {
                $this->retry->run(function () use ($restorationId, $now): void {
                    $restoration = $this->restorations->find($restorationId);
                    if (null !== $restoration) {
                        $this->entityManager->refresh($restoration);
                        $this->restorer->restore($restoration, $now);
                    }
                });
            } catch (\Throwable $exception) {
                $this->logger->error('Dossier not taken back to its point (return {restoration}): {message}', ['restoration' => $restorationId, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $this->retry->recover();
                $restoration = $this->restorations->find($restorationId);
                if (null === $restoration) {
                    return true;
                }
                $this->restorer->fail($restoration, $exception instanceof RestorationStopped ? $exception->getMessage() : 'Le retour au point n’a pas pu être fait : le dossier est resté dans l’état d’avant.', $now);
            }
            $restoration = $this->restorations->find($restorationId);
            if (null !== $restoration) {
                $this->live->announce($restoration->getDossier(), DossierLive::RESTORED);
                $this->live->announce($restoration->getDossier(), DossierLive::POINTS);
            }

            return true;
        });
    }

    /**
     * @param callable(): bool $work
     */
    private function underLock(Dossier $dossier, callable $work): bool
    {
        $lock = $this->locks->createLock(self::lockName($dossier->getId() ?? throw new \LogicException('A saved dossier is locked.')), 600.0);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
