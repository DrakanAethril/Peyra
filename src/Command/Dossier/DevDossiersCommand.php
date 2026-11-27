<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Dossier\Demo\DemoInstaller;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development only, until the distribution of step 1.8: gives every student enrolled in a class of
 * the current year their own Menuiserie Vasseur, built from the demonstration fixtures (the
 * template is installed first if missing). A student who already holds one is left as is.
 * Students are enrolled by their first sign-in: sign them in, then run it.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:dev:dossiers', description: 'Donne à chaque étudiant placé cette année son dossier de démonstration')]
final class DevDossiersCommand extends Command
{
    public function __construct(
        private readonly DemoInstaller $installer,
        private readonly SchoolYearRepository $years,
        private readonly SchoolClassRepository $classes,
        private readonly EnrolmentRepository $enrolments,
        private readonly DossierRepository $dossiers,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $year = $this->years->findCurrent();
        if (null === $year) {
            $io->error('Aucune année courante : lancez d’abord app:dev:school.');

            return Command::FAILURE;
        }
        $actor = $this->getName() ?? 'app:dev:dossiers';
        $at = $this->clock->now();
        $this->installer->installTemplate($actor, $at);
        $template = $this->installer->findTemplate() ?? throw new \LogicException('The demonstration template is installed.');

        $made = 0;
        foreach ($this->classes->findBy(['schoolYear' => $year]) as $class) {
            foreach ($this->enrolments->findByClass($class) as $enrolment) {
                $student = $enrolment->getStudent();
                if ($this->holdsOne($student)) {
                    continue;
                }
                $this->installer->buildForStudent($student, $year, $template, $actor, $at);
                ++$made;
                $io->writeln(\sprintf('  %s : %s', $student->getSortName(), $class->getName()));
            }
        }
        $io->success(0 === $made ? 'Chaque étudiant placé a déjà son dossier.' : \sprintf('%d %s.', $made, $made > 1 ? 'dossiers créés' : 'dossier créé'));

        return Command::SUCCESS;
    }

    private function holdsOne(User $student): bool
    {
        foreach ($this->dossiers->findForStudent($student) as $dossier) {
            if (DemoInstaller::DENOMINATION === $dossier->getCompany()->getDenomination()) {
                return true;
            }
        }

        return false;
    }
}
