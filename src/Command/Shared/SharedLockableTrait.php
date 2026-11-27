<?php

declare(strict_types=1);

namespace App\Command\Shared;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Symfony's LockableTrait, fixed for the scheduler's worker (taken from Formatool, same author and
 * licence). Every command that must not run twice at once uses this one, never LockableTrait:
 *
 * - **the same command instance runs again and again in one process**: the worker keeps it for an
 *   hour, and LockableTrait releases only when asked - its second run met its own first lock (« A
 *   lock is already in place »). Here the lock is released when run() returns, however execute()
 *   left;
 * - **the lock is seen from the other container**: it comes from the application's LockFactory
 *   (LOCK_DSN, a flock directory on the `app_locks` volume that `php` and `worker` both mount),
 *   so a manual run cannot land on top of a scheduled one.
 *
 *     if (!$this->lock()) {
 *         $output->writeln('Une autre exécution est en cours.');
 *
 *         return Command::SUCCESS;
 *     }
 */
trait SharedLockableTrait
{
    private ?LockFactory $sharedLockFactory = null;
    private ?LockInterface $heldLock = null;

    #[Required]
    public function setSharedLockFactory(LockFactory $lockFactory): void
    {
        $this->sharedLockFactory = $lockFactory;
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } finally {
            $this->release();
        }
    }

    /** Takes the lock named after the command, without waiting: false means another run holds it. */
    private function lock(): bool
    {
        if (null === $this->sharedLockFactory) {
            throw new \LogicException(\sprintf('%s needs a LockFactory: it is set by autowiring, see setSharedLockFactory().', static::class));
        }

        $lock = $this->sharedLockFactory->createLock('command:'.$this->getName());
        if (!$lock->acquire()) {
            return false;
        }
        $this->heldLock = $lock;

        return true;
    }

    private function release(): void
    {
        $this->heldLock?->release();
        $this->heldLock = null;
    }
}
