<?php

declare(strict_types=1);

namespace App\Command\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\ClassLevel;
use App\Repository\Identity\SchoolYearRepository;
use App\Service\Identity\AccountProvisioner;
use App\Service\Identity\Directory;
use App\Service\Identity\DirectoryGroupMappings;
use App\Service\Identity\EntryPolicy;
use App\Service\Identity\SchoolClasses;
use App\Service\Identity\SchoolYears;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Development only: the school of the development directory (plan/donnees/demo/annuaire.md) -
 * 2025-2026 behind, 2026-2027 current, « BTS CG 1 » and « BTS CG 2 » in each, `cg-1` and `cg-2`
 * mapped, last year's CG1 leading to this year's CG2, prof.martin in every class and prof.durand
 * in this year's CG1. Students are placed by their own first sign-in, as in production. Classes
 * are the institution's, so this is never part of the demonstration dossier.
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:dev:school', description: 'Années, classes, correspondance et enseignants de l’annuaire de développement')]
final class DevSchoolCommand extends Command
{
    public function __construct(
        private readonly SchoolYearRepository $years,
        private readonly SchoolYears $schoolYears,
        private readonly SchoolClasses $classes,
        private readonly DirectoryGroupMappings $mappings,
        private readonly Directory $directory,
        private readonly EntryPolicy $policy,
        private readonly AccountProvisioner $provisioner,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ([] !== $this->years->findAllNewestFirst()) {
            $io->note('Des années scolaires existent déjà : rien n’est fait.');

            return Command::SUCCESS;
        }

        $at = $this->clock->now();
        $martin = $this->teacher('prof.martin', $at);
        $durand = $this->teacher('prof.durand', $at);

        [$last, $lastCg1, $lastCg2] = $this->year(2025, $martin, $at);
        [$current, $cg1, $cg2] = $this->year(2026, $martin, $at);
        $this->schoolYears->makeCurrent($current, $martin, $at);
        $this->classes->update($lastCg1, $lastCg1->getName(), $lastCg1->getLevel(), $cg2, $martin, $at);
        foreach ([$lastCg1, $lastCg2, $cg1, $cg2] as $class) {
            $this->classes->addTeacher($class, $martin, $martin, $at);
        }
        $this->classes->addTeacher($cg1, $durand, $martin, $at);

        $io->success(\sprintf('%s (passée) et %s (courante), deux classes chacune ; les étudiants seront placés à leur première connexion.', $last->getLabel(), $current->getLabel()));

        return Command::SUCCESS;
    }

    /** @return array{SchoolYear, SchoolClass, SchoolClass} */
    private function year(int $start, User $actor, \DateTimeImmutable $at): array
    {
        $year = $this->schoolYears->create(new \DateTimeImmutable($start.'-09-01'), new \DateTimeImmutable(($start + 1).'-08-31'), false, $actor, $at);
        if (\is_string($year)) {
            throw new \RuntimeException($year);
        }
        $cg1 = $this->classes->create($year, 'BTS CG 1', ClassLevel::First, $actor, $at);
        $cg2 = $this->classes->create($year, 'BTS CG 2', ClassLevel::Second, $actor, $at);
        if (\is_string($cg1) || \is_string($cg2)) {
            throw new \RuntimeException(\is_string($cg1) ? $cg1 : (string) $cg2);
        }
        $this->mappings->save($year, ['cg-1' => $cg1, 'cg-2' => $cg2], $actor, $at);

        return [$year, $cg1, $cg2];
    }

    /** The teacher's account, created from the directory as a first sign-in would. */
    private function teacher(string $login, \DateTimeImmutable $at): User
    {
        $person = $this->directory->find($login) ?? throw new \RuntimeException($login.' is not in the directory.');
        $decision = $this->policy->decide($person->login, $person->stableId, $person->groups);

        return $this->provisioner->provision($person, $decision, $at);
    }
}
