<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Command\Shared\SharedLockableTrait;
use App\Repository\Dossier\DistributionCopyRepository;
use App\Service\Dossier\Distribution\DistributionRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes the dossiers of the distributions still waiting - those a request could not finish after
 * its answer (a worker restarted, a container stopped) -, one copy per pass, the oldest first,
 * until none is left. Scheduled every minute (App\Scheduler\PeyraSchedule); usually it finds
 * nothing. Each copy takes its own lock, so a request making it at the same moment is never
 * doubled, and a copy left half way is made again from nothing.
 */
#[AsCommand(name: 'app:distribution:advance', description: 'Crée les dossiers des distributions restés en attente')]
final class AdvanceDistributionsCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly DistributionCopyRepository $copies,
        private readonly DistributionRunner $runner,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->lock()) {
            $io->note('Une autre exécution est en cours.');

            return Command::SUCCESS;
        }
        $made = 0;
        foreach ($this->copies->findPendingIds() as $id) {
            if ($this->runner->make($id, $this->clock->now())) {
                ++$made;
            }
        }
        $io->writeln(\sprintf('%d dossier(s) de distribution traité(s).', $made));

        return Command::SUCCESS;
    }
}
