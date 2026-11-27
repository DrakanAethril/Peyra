<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Service\Dossier\Demo\DemoInstaller;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Installs the demonstration dossier, Menuiserie Vasseur SARL, as a template of the common library
 * (plan README § 11): in development and in production, where it is the first template the
 * référents receive. Run again, it changes nothing: an installed template is never replaced nor
 * deleted - what a teacher did to it stays.
 */
#[AsCommand(name: 'app:demo:install', description: 'Installe le dossier de démonstration (Menuiserie Vasseur SARL) dans la bibliothèque commune')]
final class DemoInstallCommand extends Command
{
    public function __construct(private readonly DemoInstaller $installer, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dossier = $this->installer->installTemplate($this->getName() ?? 'app:demo:install', $this->clock->now());
        if (null === $dossier) {
            $io->note('Le dossier de démonstration est déjà installé : rien n’est fait.');

            return Command::SUCCESS;
        }
        $io->success(\sprintf('%s installé dans la bibliothèque commune (dossier n° %d).', $dossier->getName(), $dossier->getId()));

        return Command::SUCCESS;
    }
}
