<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\TeacherClassRepository;

/**
 * The reading of a year's classes for the lists, the class switcher and the homes: effectif,
 * teachers, the reader's own classes first. A fixed number of queries whatever the class count.
 */
final readonly class ClassBoard
{
    public function __construct(
        private SchoolYearRepository $years,
        private SchoolClassRepository $classes,
        private EnrolmentRepository $enrolments,
        private TeacherClassRepository $teachers,
    ) {
    }

    public function currentYear(): ?SchoolYear
    {
        return $this->years->findCurrent();
    }

    /** @return list<ClassRow> the year's classes, the reader's own first, then by level and name */
    public function rows(SchoolYear $year, ?User $reader = null): array
    {
        $counts = $this->enrolments->countByClassInYear($year);
        $teachersByClass = [];
        foreach ($this->teachers->findByYear($year) as $assignment) {
            $teachersByClass[(int) $assignment->getSchoolClass()->getId()][] = $assignment->getTeacher();
        }
        $rows = [];
        foreach ($this->classes->findByYear($year) as $class) {
            $id = (int) $class->getId();
            $teachers = $teachersByClass[$id] ?? [];
            $rows[] = new ClassRow($class, $counts[$id] ?? 0, $teachers, null !== $reader && \in_array($reader, $teachers, true));
        }
        usort($rows, static fn (ClassRow $a, ClassRow $b): int => [!$a->mine, $a->schoolClass->getLevel()->value, $a->schoolClass->getName()]
            <=> [!$b->mine, $b->schoolClass->getLevel()->value, $b->schoolClass->getName()]);

        return $rows;
    }

    public function row(SchoolClass $class, ?User $reader = null): ClassRow
    {
        foreach ($this->rows($class->getSchoolYear(), $reader) as $row) {
            if ($row->schoolClass === $class) {
                return $row;
            }
        }

        throw new \LogicException('A class is among the classes of its year.');
    }

    /** @return list<SchoolYear> the years before the current one, the most recent first (all of them without a current year) */
    public function pastYears(): array
    {
        $current = $this->years->findCurrent();

        return null === $current ? [] : $this->years->findBefore($current);
    }
}
