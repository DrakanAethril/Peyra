<?php

declare(strict_types=1);

namespace App\Tests\Command\Shared;

use App\Command\Shared\SharedLockableTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SharedLockableTraitTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/peyra-locks-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    public function testTheSameInstanceRunsAgainInOneProcess(): void
    {
        $command = $this->command(new LockFactory(new FlockStore($this->directory)));

        self::assertSame(Command::SUCCESS, $this->execute($command));
        self::assertSame(Command::SUCCESS, $this->execute($command), 'The worker reruns the same instance: the first run released its lock.');
        self::assertSame(Command::SUCCESS, $this->execute($command, fail: true));
        self::assertSame(Command::SUCCESS, $this->execute($command), 'A run that threw released its lock too.');
    }

    public function testARunElsewhereHoldsTheLock(): void
    {
        $command = $this->command(new LockFactory(new FlockStore($this->directory)));
        $elsewhere = (new LockFactory(new FlockStore($this->directory)))->createLock('command:app:test:locked');
        self::assertTrue($elsewhere->acquire());

        try {
            self::assertSame(Command::FAILURE, $this->execute($command), 'Another container holds the lock through the shared directory.');
        } finally {
            $elsewhere->release();
        }
        self::assertSame(Command::SUCCESS, $this->execute($command));
    }

    private function command(LockFactory $factory): Command
    {
        $command = new class extends Command {
            use SharedLockableTrait;

            public function __construct()
            {
                parent::__construct('app:test:locked');
            }

            protected function configure(): void
            {
                $this->addOption('fail');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if (!$this->lock()) {
                    return Command::FAILURE;
                }
                if (true === $input->getOption('fail')) {
                    throw new \RuntimeException('boom');
                }

                return Command::SUCCESS;
            }
        };
        $command->setSharedLockFactory($factory);

        return $command;
    }

    private function execute(Command $command, bool $fail = false): int
    {
        try {
            return $command->run(new ArrayInput($fail ? ['--fail' => true] : []), new BufferedOutput());
        } catch (\RuntimeException) {
            return Command::SUCCESS;
        }
    }
}
