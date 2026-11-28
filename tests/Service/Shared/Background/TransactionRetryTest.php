<?php

declare(strict_types=1);

namespace App\Tests\Service\Shared\Background;

use App\Service\Shared\Background\TransactionRetry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The work of a dossier given up by the database (two returns of different dossiers at the same
 * moment, step 1.10c) is made again; anything else stops at once. The case met: the deadlock
 * hidden behind « SAVEPOINT DOCTRINE_2 does not exist », the connection left counting a
 * transaction that no longer exists.
 */
final class TransactionRetryTest extends TestCase
{
    public function testADeadlockIsMadeAgain(): void
    {
        $calls = 0;
        $result = $this->retry($this->connection())->run(function () use (&$calls): string {
            if (1 === ++$calls) {
                throw self::deadlock();
            }

            return 'fait';
        });

        self::assertSame('fait', $result);
        self::assertSame(2, $calls);
    }

    public function testTheDeadlockHiddenBehindTheLostSavepointIsMadeAgainOnAFreshConnection(): void
    {
        // What MariaDB answered: the deadlock rolled the whole transaction back, the nested
        // transaction's ROLLBACK TO SAVEPOINT failed, and DBAL kept counting two levels.
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(0);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('close');
        $calls = 0;
        $this->retry($connection)->run(function () use (&$calls): void {
            if (1 === ++$calls) {
                throw self::driverError('SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT DOCTRINE_2 does not exist', self::deadlock());
            }
        });

        self::assertSame(2, $calls);
    }

    public function testALockWaitGivenUpIsMadeAgain(): void
    {
        $calls = 0;
        $this->retry($this->connection())->run(function () use (&$calls): void {
            if (++$calls < TransactionRetry::ATTEMPTS) {
                throw new LockWaitTimeoutException(PdoDriverException::new(new \PDOException('Lock wait timeout exceeded')), null);
            }
        });

        self::assertSame(TransactionRetry::ATTEMPTS, $calls);
    }

    public function testItGivesUpAfterThreeAttempts(): void
    {
        $calls = 0;
        try {
            $this->retry($this->connection())->run(function () use (&$calls): void {
                ++$calls;
                throw self::deadlock();
            });
            self::fail('Trois verrous mortels de suite : la copie est dite en échec.');
        } catch (DeadlockException) {
        }

        self::assertSame(3, $calls);
    }

    public function testAnyOtherFailureStopsAtOnce(): void
    {
        $calls = 0;
        $this->expectException(\LogicException::class);
        try {
            $this->retry($this->connection())->run(function () use (&$calls): void {
                ++$calls;
                throw new \LogicException('Le point demandé n’est plus là.');
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testInsideTheCallersTransactionNothingIsMadeAgain(): void
    {
        // Only the outermost transaction can be issued again: a savepoint cannot.
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->expects(self::never())->method('close');
        $calls = 0;
        $this->expectException(DeadlockException::class);
        try {
            $this->retry($connection)->run(function () use (&$calls): void {
                ++$calls;
                throw self::deadlock();
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testRecoveringLeavesAHealthyConnectionAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects(self::never())->method('close');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');

        (new TransactionRetry($connection, $entityManager, $registry))->recover();
    }

    private function retry(Connection $connection): TransactionRetry
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);

        return new TransactionRetry($connection, $entityManager, $this->createStub(ManagerRegistry::class));
    }

    private function connection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(0);
        $connection->method('isTransactionActive')->willReturn(false);

        return $connection;
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(PdoDriverException::new(new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')), null);
    }

    private static function driverError(string $message, \Throwable $previous): DriverException
    {
        return new DriverException(PdoDriverException::new(new \PDOException($message, 0, $previous)), null);
    }
}
