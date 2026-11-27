<?php

declare(strict_types=1);

namespace App\Command\Regulatory;

use App\Command\Shared\SharedLockableTrait;
use App\Service\Regulatory\InvalidRegulatoryFile;
use App\Service\Regulatory\RegulatorySync;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs the millésime files (config/regulatory/<année>.yaml): run by every deployment
 * (docs/production.md), harmless to run again - it only adds what is missing or changed, and
 * never overwrites a value an administrator entered (App\Service\Regulatory\RegulatorySync).
 * Not scheduled.
 */
#[AsCommand(name: 'app:regulatory:sync', description: 'Installe les millésimes réglementaires du dépôt (config/regulatory)')]
final class RegulatorySyncCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(private readonly RegulatorySync $sync, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dire ce qui serait installé, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->lock()) {
            $io->warning('Une autre installation des millésimes est en cours.');

            return Command::SUCCESS;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $reports = $this->sync->run($this->clock->now(), $dryRun);
        } catch (InvalidRegulatoryFile $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        if ([] === $reports) {
            $io->warning('Aucun fichier de millésime dans config/regulatory.');

            return Command::SUCCESS;
        }
        foreach ($reports as $report) {
            $io->writeln(\sprintf('<info>%s</info> : %s', $report->fileName, $report->summary()));
            foreach (['ajoutée' => $report->added, 'mise à jour' => $report->updated, 'conservée' => $report->kept] as $what => $lines) {
                foreach ($lines as $line) {
                    $io->writeln(\sprintf('  %s : %s', $what, $line));
                }
            }
        }
        if ($dryRun) {
            $io->note('Essai à blanc : rien n’a été écrit.');
        }

        return Command::SUCCESS;
    }
}
