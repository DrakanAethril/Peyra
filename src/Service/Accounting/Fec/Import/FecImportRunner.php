<?php

declare(strict_types=1);

namespace App\Service\Accounting\Fec\Import;

use App\Entity\Accounting\FecImportJob;
use App\Service\Shared\Background\TransactionRetry;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs the passes of a FEC import in the background - after the answer of the request that asked
 * (FecImportWorkListener), or by app:fec-import:advance for what a stopped worker left -, each
 * under the import's lock (LOCK_DSN, seen by `php` and `worker` alike) and in its own
 * transaction: a pass cut short is rolled back and run again by the next one.
 *
 * A pass the database gave up (a deadlock with a copy made at the same moment) is run again, up to
 * three times (TransactionRetry). A pass that fails otherwise stops the import: logged at error level (it reaches the operator), said on
 * the progress page - « Relancer » or « Abandonner » -, never retried on its own. Each pass is
 * announced to the page.
 */
final readonly class FecImportRunner
{
    public const string FAILURE = 'L’import s’est arrêté sur une erreur inattendue. Relancez-le ; s’il s’arrête de nouveau, abandonnez-le et signalez le fichier.';

    public function __construct(
        private LockFactory $locks,
        private ManagerRegistry $registry,
        private TransactionRetry $retry,
        private FecImport $import,
        private FecImportLive $live,
        private LoggerInterface $logger,
    ) {
    }

    public static function lockName(int $jobId): string
    {
        return 'fec-import-'.$jobId;
    }

    /**
     * One pass; true when it ran and passes are left - callers loop on it, reading the time again
     * before each one, until the import is done, stopped, or held by the other side.
     */
    public function pass(int $jobId, \DateTimeImmutable $now, int $size = FecImport::PASS_ENTRIES): bool
    {
        $lock = $this->locks->createLock(self::lockName($jobId), 600.0);
        if (!$lock->acquire()) {
            return false;
        }
        try {
            try {
                $left = $this->retry->run(fn (): bool => $this->import->pass($jobId, $now, $size));
            } catch (\Throwable $exception) {
                $this->logger->error('FEC import {job} stopped: {message}', ['job' => $jobId, 'message' => $exception->getMessage(), 'exception' => $exception]);
                $this->stop($jobId, $exception, $now);
                $left = false;
            }
            $this->live->announce($jobId);

            return $left;
        } finally {
            $lock->release();
        }
    }

    private function stop(int $jobId, \Throwable $exception, \DateTimeImmutable $now): void
    {
        $this->retry->recover();
        $entityManager = $this->registry->getManager();
        $job = $entityManager->find(FecImportJob::class, $jobId);
        if (null === $job) {
            return;
        }
        $finding = $exception instanceof FecImportRefused ? ($exception->findings[0] ?? null) : null;
        $job->fail(null === $finding ? self::FAILURE : \sprintf('%s%s', null === $finding->line ? '' : 'Ligne '.$finding->line.' : ', $finding->message), $now);
        $entityManager->flush();
    }
}
