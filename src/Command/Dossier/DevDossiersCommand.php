<?php

declare(strict_types=1);

namespace App\Command\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\UserRepository;
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
 * template is installed first if missing), and one group's dossier to the students of GROUP who
 * are enrolled, each with their profile (Inès Morel « Comptable fournisseurs » of the mock-ups). A
 * student who already holds one is left as is, and so is a group's dossier already made.
 * Students are enrolled by their first sign-in: sign them in, then run it.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:dev:dossiers', description: 'Donne à chaque étudiant placé cette année son dossier de démonstration')]
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
        $made += $this->group($year, $template, $actor, $at, $io);
        $io->success(0 === $made ? 'Chaque étudiant placé a déjà son dossier.' : \sprintf('%d %s.', $made, $made > 1 ? 'dossiers créés' : 'dossier créé'));

        return Command::SUCCESS;
    }

    /** The group's dossier, for the group's students enrolled this year; 1 when made. */
    private function group(SchoolYear $year, Dossier $template, string $actor, \DateTimeImmutable $at, SymfonyStyle $io): int
    {
        $members = [];
        foreach (self::GROUP as $login => $profile) {
            $student = $this->users->findOneBy(['login' => $login]);
            if (null !== $student && null !== $this->enrolments->findOneBy(['student' => $student, 'schoolYear' => $year])) {
                if ($this->holdsOne($student, DossierKind::Group)) {
                    return 0;
                }
                $members[] = [$student, $profile];
            }
        }
        if (\count($members) < 2) {
            return 0;
        }
        $this->installer->buildForGroup($members, $year, $template, $actor, $at);
        $io->writeln(\sprintf('  Groupe : %s', implode(', ', array_map(static fn (array $member): string => $member[0]->getDisplayName().' ('.$member[1]->label().')', $members))));

        return 1;
    }

    private function holdsOne(User $student, DossierKind $kind = DossierKind::Student): bool
    {
        foreach ($this->dossiers->findForStudent($student) as $dossier) {
            if ($kind === $dossier->getKind() && DemoInstaller::DENOMINATION === $dossier->getCompany()->getDenomination()) {
                return true;
            }
        }

        return false;
    }
}
