<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Command\Shared\SharedLockableTrait;
use App\Repository\Dossier\DossierRestorationRepository;
use App\Repository\Dossier\RestorePointRepository;
use App\Service\Dossier\Restore\RestoreRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finishes the restore work a request asked for and could not do after its answer (a worker
 * restarted, a container stopped): the points still in preparation, the returns still running.
 * Scheduled every minute (App\Scheduler\PeyraSchedule); usually it finds nothing. Each piece of
 * work takes its dossier's lock, so a request finishing it at the same moment is never doubled.
 */
#[AsCommand(name: 'app:restore-points:advance', description: 'Termine les points de restauration et les retours à un point restés en cours')]
final class AdvanceRestorePointsCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly RestorePointRepository $points,
        private readonly DossierRestorationRepository $restorations,
        private readonly RestoreRunner $runner,
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
        $points = 0;
        foreach (array_map(static fn ($point): ?int => $point->getId(), $this->points->findPreparing()) as $id) {
            if (null !== $id && $this->runner->preparePoint($id, $this->clock->now())) {
                ++$points;
            }
        }
        $returns = 0;
        foreach (array_map(static fn ($restoration): ?int => $restoration->getId(), $this->restorations->findAllRunning()) as $id) {
            if (null !== $id && $this->runner->restore($id, $this->clock->now())) {
                ++$returns;
            }
        }
        $io->writeln(\sprintf('%d point(s) de restauration préparé(s), %d retour(s) à un point fait(s).', $points, $returns));

        return Command::SUCCESS;
    }
}
