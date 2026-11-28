<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DistributionCopyRepository;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\UserRepository;
use App\Service\Dossier\Demo\DemoInstaller;
use App\Service\Dossier\Distribution\DistributionChoices;
use App\Service\Dossier\Distribution\DistributionPlanner;
use App\Service\Dossier\Distribution\DistributionRunner;
use App\Service\Dossier\Distribution\Distributions;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development only: distributes Menuiserie Vasseur (the common library's template, installed
 * first if missing) to each class of the current year - a dossier to every student who holds none
 * from it yet -, then to the demonstration group as one shared dossier, each member with their
 * profile (Inès Morel « Comptable fournisseurs » of the mock-ups), unless they already share one.
 * Each distribution goes through the distribution's own door, without an author, and its copies
 * are made here at once. Students are enrolled by their first sign-in: sign them in, then run it.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:dev:dossiers', description: 'Distribue le dossier de démonstration aux étudiants placés cette année')]
final class DevDossiersCommand extends Command
{
    /** The demonstration group: logins and their profiles. */
    public const array GROUP = [
        'ines.morel' => DossierProfile::SupplierAccountant,
        'theo.garnier' => DossierProfile::CustomerAccountant,
        'chloe.fabre' => DossierProfile::ChiefAccountant,
    ];

    public function __construct(
        private readonly DemoInstaller $installer,
        private readonly SchoolYearRepository $years,
        private readonly SchoolClassRepository $classes,
        private readonly EnrolmentRepository $enrolments,
        private readonly DossierRepository $dossiers,
        private readonly UserRepository $users,
        private readonly DistributionPlanner $planner,
        private readonly Distributions $distributions,
        private readonly DistributionCopyRepository $copies,
        private readonly DistributionRunner $runner,
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
        $this->installer->installTemplate($this->getName() ?? 'app:dev:dossiers', $this->clock->now());
        $template = $this->installer->findTemplate() ?? throw new \LogicException('The demonstration template is installed.');

        $made = 0;
        foreach ($this->classes->findByYear($year) as $class) {
            $plan = $this->planner->plan($template, $class, DistributionChoices::first());
            if ([] === $plan->ticked()) {
                continue;
            }
            $made += $this->distribute($this->distributions->launch($plan, null, $this->clock->now()), $io);
        }
        $made += $this->group($template, $year, $io);
        $io->success(0 === $made ? 'Chaque étudiant placé a déjà son dossier.' : \sprintf('%d %s.', $made, $made > 1 ? 'dossiers créés' : 'dossier créé'));

        return Command::SUCCESS;
    }

    /** The group's shared dossier, for its students enrolled in one class this year who share none yet. */
    private function group(Dossier $template, SchoolYear $year, SymfonyStyle $io): int
    {
        $class = null;
        $picked = [];
        $profiles = [];
        foreach (self::GROUP as $login => $profile) {
            $student = $this->users->findOneBy(['login' => $login]);
            $enrolment = null === $student ? null : $this->enrolments->findOneForStudentAndYear($student, $year);
            if (null === $student || null === $enrolment || ($class instanceof SchoolClass && $enrolment->getSchoolClass() !== $class)) {
                continue;
            }
            if ($this->sharesOne($student, $template)) {
                return 0;
            }
            $class = $enrolment->getSchoolClass();
            $id = $student->getId() ?? 0;
            $picked[] = $id;
            $profiles[$id] = $profile;
        }
        if (!$class instanceof SchoolClass || \count($picked) < 2) {
            return 0;
        }
        $choices = new DistributionChoices($picked, DistributionMode::Group, array_fill_keys($picked, 1), $profiles, '');

        return $this->distribute($this->distributions->launch($this->planner->plan($template, $class, $choices), null, $this->clock->now()), $io);
    }

    /** Makes the distribution's copies now; answers how many dossiers it made. */
    private function distribute(Distribution $distribution, SymfonyStyle $io): int
    {
        $made = 0;
        foreach ($this->copies->findPendingIds($distribution) as $copy) {
            $this->runner->make($copy, $this->clock->now());
        }
        foreach ($this->copies->findByDistribution($distribution) as $copy) {
            if (null !== $copy->getDossier()) {
                ++$made;
                $io->writeln(\sprintf('  %s : %s', $distribution->getSchoolClass()->getName(), Distributions::holderOf($copy)));
            } else {
                $io->warning(\sprintf('%s : %s', Distributions::holderOf($copy), (string) $copy->getFailure()));
            }
        }

        return $made;
    }

    private function sharesOne(User $student, Dossier $template): bool
    {
        foreach ($this->dossiers->findForStudent($student) as $dossier) {
            if (DossierKind::Group === $dossier->getKind() && $dossier->getSourceTemplate() === $template) {
                return true;
            }
        }

        return false;
    }
}
