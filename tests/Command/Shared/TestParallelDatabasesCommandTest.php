<?php

declare(strict_types=1);

namespace App\Tests\Command\Shared;

use App\Command\Shared\TestParallelDatabasesCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TestParallelDatabasesCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function databases(): iterable
    {
        yield 'workstation' => ['peyra_test', 'peyra_test'];
        yield 'CI' => ['peyra_testci', 'peyra_test'];
        yield 'a ParaTest copy' => ['peyra_test3', 'peyra_test'];
        yield 'the development database' => ['peyra', null];
        yield 'a name that only contains _test' => ['peyra_test_archive', null];
    }

    #[DataProvider('databases')]
    public function testRecognisesTestDatabases(string $database, ?string $prefix): void
    {
        self::assertSame($prefix, TestParallelDatabasesCommand::testPrefix($database));
    }

    public function testRefusesToRunOutsideTheTestEnvironment(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = new CommandTester(new TestParallelDatabasesCommand($connection, 'dev'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('APP_ENV=test', $tester->getDisplay());
    }
}
