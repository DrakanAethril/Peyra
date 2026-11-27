<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\DirectoryGroupMapping;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use App\Repository\Identity\DirectoryGroupMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administration › Correspondance avec l'annuaire (conception § 3 bis): once a year, each student
 * group of the directory (`PEYRA_STUDENT_GROUPS`) points to a class of the year, or to none. Read
 * by InitialPlacement only, when a student's account is created.
 */
final readonly class DirectoryGroupMappings
{
    public function __construct(
        private DirectoryGroupMappingRepository $mappings,
        private EntryPolicy $policy,
        private AdministrationJournal $journal,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<string> the groups the screen offers: the student groups, then any group mapped already */
    public function groups(SchoolYear $year): array
    {
        $groups = $this->policy->studentGroups();
        foreach (array_keys($this->mappings->findByYear($year)) as $group) {
            if (!\in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /** @return array<string, SchoolClass> the class each group points to, by group */
    public function classByGroup(SchoolYear $year): array
    {
        return array_map(static fn (DirectoryGroupMapping $m): SchoolClass => $m->getSchoolClass(), $this->mappings->findByYear($year));
    }

    /**
     * Writes the year's mapping: a group to a class, or to none (its row goes).
     *
     * @param array<string, SchoolClass|null> $choices by group
     *
     * @return int how many groups changed
     */
    public function save(SchoolYear $year, array $choices, User $actor, \DateTimeImmutable $at): int
    {
        $existing = $this->mappings->findByYear($year);
        $changed = 0;
        foreach ($choices as $group => $class) {
            $group = mb_strtolower($group);
            if (null !== $class && $class->getSchoolYear() !== $year) {
                throw new \LogicException('A group points to a class of the year being mapped.');
            }
            $mapping = $existing[$group] ?? null;
            if ($mapping?->getSchoolClass() === $class) {
                continue;
            }
            if (null === $class) {
                \assert(null !== $mapping);
                $this->entityManager->remove($mapping);
            } elseif (null === $mapping) {
                $this->entityManager->persist(new DirectoryGroupMapping($group, $class));
            } else {
                $mapping->pointTo($class);
            }
            $this->journal->record($at, $actor, AdministrationAction::DirectoryMappingSaved, $year->getLabel(), $year->getLabel(), $group.' → '.($class?->getName() ?? 'aucune classe'));
            ++$changed;
        }
        $this->entityManager->flush();

        return $changed;
    }
}
