<?php

declare(strict_types=1);

namespace App\Service\Shared\Background;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Re-issues a unit of background work whose transaction the database gave up - a deadlock, a lock
 * wait timed out -, as InnoDB asks of its clients. Two copies or returns of **different** dossiers
 * at the same moment (the request's kernel.terminate and the scheduled command) delete and insert
 * next to each other on the `dossier_id` indexes under REPEATABLE READ's next-key locks, and one
 * of them is chosen as the victim (step 1.10c, reproduced with four concurrent returns).
 *
 * Two traps of that victim, both met: InnoDB rolls the whole transaction back, its savepoints
 * with it, so the nested transaction's `ROLLBACK TO SAVEPOINT DOCTRINE_2` fails and hides the
 * deadlock (« SAVEPOINT DOCTRINE_2 does not exist », the deadlock among its previous exceptions);
 * and DBAL then keeps counting a transaction that no longer exists - every later « transaction »
 * of the worker would be a savepoint in autocommit. recover() closes such a connection: the next
 * statement opens a fresh one at level 0.
 *
 * The work reads again what it needs at each attempt (ids, never entities of a previous attempt).
 */
final readonly class TransactionRetry
{
    public const int ATTEMPTS = 3;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $registry,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            $level = $this->connection->getTransactionNestingLevel();
            try {
                return $work();
            } catch (\Throwable $exception) {
                if ($level > 0 || $attempt >= self::ATTEMPTS || !self::retryable($exception)) {
                    throw $exception;
                }
                $this->recover();
                usleep(random_int(50, 250) * 1000);
            }
        }
    }

    /**
     * After a failure, outside any transaction of the caller's: a connection at level 0 and an
     * entity manager that flushes again, with nothing stale in it.
     */
    public function recover(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->close();
        }
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        } else {
            $this->registry->resetManager();
        }
    }

    /** Whether the database gave the transaction up, the exception itself or one it hides. */
    public static function retryable(\Throwable $exception): bool
    {
        for ($cause = $exception; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryableException) {
                return true;
            }
        }

        return false;
    }
}
