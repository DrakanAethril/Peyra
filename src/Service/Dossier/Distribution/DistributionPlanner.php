<?php

declare(strict_types=1);

namespace App\Service\Dossier\Distribution;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Repository\Dossier\DossierRepository;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use App\Repository\Identity\TeacherClassRepository;

/**
 * Reads what a distribution's steps need (design-ux.md § 4.2): the classes of the current year a
 * template is handed out to - the teacher's own first -, a class's students and those of them who
 * already hold a copy of the template, then DistributionPlan decides.
 */
final readonly class DistributionPlanner
{
    public function __construct(
        private SchoolYearRepository $years,
        private SchoolClassRepository $classes,
        private TeacherClassRepository $teacherClasses,
        private EnrolmentRepository $enrolments,
        private DossierRepository $dossiers,
    ) {
    }

    /** @return list<SchoolClass> the current year's classes; empty without a current year */
    public function classes(): array
    {
        $year = $this->years->findCurrent();

        return null === $year ? [] : $this->classes->findByYear($year);
    }

    /**
     * The class a distribution is for: the one asked, if it is of the current year (null
     * otherwise); without one asked, the first class the teacher teaches, else the first class.
     */
    public function classFor(User $teacher, ?int $asked): ?SchoolClass
    {
        $classes = $this->classes();
        if (null !== $asked) {
            foreach ($classes as $class) {
                if ($class->getId() === $asked) {
                    return $class;
                }
            }

            return null;
        }
        if ([] === $classes) {
            return null;
        }
        $mine = $this->teacherClasses->findClassIdsOf($teacher, $classes[0]->getSchoolYear());
        foreach ($classes as $class) {
            if (\in_array($class->getId(), $mine, true)) {
                return $class;
            }
        }

        return $classes[0];
    }

    public function plan(Dossier $template, SchoolClass $class, DistributionChoices $choices): DistributionPlan
    {
        $students = array_map(static fn ($enrolment): User => $enrolment->getStudent(), $this->enrolments->findByClass($class));
        $holders = $this->dossiers->findStudentIdsHoldingCopyOf($template, array_map(static fn (User $student): int => $student->getId() ?? 0, $students));

        return DistributionPlan::of($template, $class, $students, $holders, $choices);
    }
}
