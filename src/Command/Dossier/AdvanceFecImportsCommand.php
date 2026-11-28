<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Command\Shared\SharedLockableTrait;
use App\Repository\Accounting\FecImportJobRepository;
use App\Service\Accounting\Fec\Import\FecImportRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the passes of the FEC imports still pending - those a request could not finish after its
 * answer (a worker restarted, a container stopped) -, the oldest first, until each is done or
 * stopped. Scheduled every minute (App\Scheduler\PeyraSchedule); usually it finds nothing. Each pass
 * takes the import's lock, so a request running it at the same moment is never doubled, and a pass
 * cut short is run again from where the last one committed.
 */
#[AsCommand(name: 'app:fec-import:advance', description: 'Poursuit les imports de FEC restés en cours')]
final class AdvanceFecImportsCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly FecImportJobRepository $jobs,
        private readonly FecImportRunner $runner,
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
        $ids = $this->jobs->findPendingIds();
        foreach ($ids as $id) {
            while ($this->runner->pass($id, $this->clock->now())) {
            }
        }
        $io->writeln(\sprintf('%d import(s) de FEC traité(s).', \count($ids)));

        return Command::SUCCESS;
    }
}
