<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Enum\Identity\ClassLevel;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\TeacherClassRepository;

/**
 * What the rentrée needs before the passage de classe and the first sign-ins (design-ux.md
 * § 4.12, step 1): a current year, its classes, the mapping of every student group, the next class
 * of last year's first-year classes, a teacher in each class. Each check says what is missing.
 */
final readonly class YearPreparation
{
    public function __construct(
        private SchoolYearRepository $years,
        private SchoolClassRepository $classes,
        private TeacherClassRepository $teachers,
        private DirectoryGroupMappings $mappings,
    ) {
    }

    /** @return list<PreparationCheck> */
    public function checks(): array
    {
        $year = $this->years->findCurrent();
        if (null === $year) {
            return [new PreparationCheck(false, 'Aucune année scolaire n’est courante.', 'years')];
        }
        $checks = [new PreparationCheck(true, \sprintf('Année courante : %s.', $year->getLabel()), 'years')];

        $classes = $this->classes->findByYear($year);
        $checks[] = [] === $classes
            ? new PreparationCheck(false, \sprintf('L’année %s n’a pas encore de classe.', $year->getLabel()), 'classes')
            : new PreparationCheck(true, \sprintf('%d %s en %s.', \count($classes), \count($classes) > 1 ? 'classes' : 'classe', $year->getLabel()), 'classes');

        $mapped = $this->mappings->classByGroup($year);
        $groups = $this->mappings->groups($year);
        $unmapped = array_values(array_filter($groups, static fn (string $g): bool => !isset($mapped[$g])));
        $checks[] = [] === $unmapped
            ? new PreparationCheck(true, 'Chaque groupe de l’annuaire place dans une classe.', 'mapping')
            : new PreparationCheck(false, \sprintf('Groupes de l’annuaire sans classe : %s.', implode(', ', $unmapped)), 'mapping');

        $previous = $this->years->findPrevious($year);
        if (null !== $previous) {
            $firstYear = array_filter($this->classes->findByYear($previous), static fn ($c): bool => ClassLevel::First === $c->getLevel());
            $without = array_values(array_filter($firstYear, static fn ($c): bool => null === $c->getNextClass()));
            if ([] !== $firstYear) {
                $checks[] = [] === $without
                    ? new PreparationCheck(true, \sprintf('Les classes de 1re année de %s nomment leur classe suivante.', $previous->getLabel()), 'classes')
                    : new PreparationCheck(false, \sprintf('Sans classe suivante : %s.', implode(', ', array_map(static fn ($c): string => $c->getLabel(), $without))), 'classes');
            }
        }

        if ([] !== $classes) {
            $taught = [];
            foreach ($this->teachers->findByYear($year) as $assignment) {
                $taught[(int) $assignment->getSchoolClass()->getId()] = true;
            }
            $alone = array_values(array_filter($classes, static fn ($c): bool => !isset($taught[(int) $c->getId()])));
            $checks[] = [] === $alone
                ? new PreparationCheck(true, 'Chaque classe a au moins un enseignant.', 'classes')
                : new PreparationCheck(false, \sprintf('Sans enseignant : %s.', implode(', ', array_map(static fn ($c): string => $c->getName(), $alone))), 'classes');
        }

        return $checks;
    }

    public function isComplete(): bool
    {
        foreach ($this->checks() as $check) {
            if (!$check->passed) {
                return false;
            }
        }

        return true;
    }
}
