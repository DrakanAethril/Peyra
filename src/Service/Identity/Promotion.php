<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Identity\EnrolmentOrigin;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The « passage de classe » in one gesture (conception § 3 bis, design-ux.md § 4.12), manual: a
 * class of last year moves into the class it names as next. Every student is ticked; the teacher
 * unticks those who repeat (another class of the new year), those not placed, those who leave.
 * The gesture can be made again without harm: a student already enrolled in the new year is left
 * as is. Dossiers are not touched.
 */
final readonly class Promotion
{
    public function __construct(
        private SchoolClassRepository $classes,
        private SchoolYearRepository $years,
        private EnrolmentRepository $enrolments,
        private Enrolments $placements,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The classes a passage starts from: those naming a next class in the current year or a later
     * one, the most recent first.
     *
     * @return list<SchoolClass>
     */
    public function sources(): array
    {
        $current = $this->years->findCurrent();
        $sources = [];
        foreach ($this->years->findAllNewestFirst() as $year) {
            if (null !== $current && $year->getStartsOn() < $current->getStartsOn()) {
                break;
            }
            foreach ($this->classes->findLeadingInto($year) as $class) {
                $sources[] = $class;
            }
        }

        return $sources;
    }

    /** @return list<SchoolClass> the classes a student of this passage may go to instead */
    public function targetClasses(SchoolClass $source): array
    {
        $next = $source->getNextClass() ?? throw new \LogicException('A passage starts from a class that names its next class.');

        return $this->classes->findByYear($next->getSchoolYear());
    }

    /**
     * The plan for a source class; `choices` by student id, every student ticked when absent.
     *
     * @param array<int, PromotionChoice>|null $choices null for the first display: everybody ticked
     */
    public function plan(SchoolClass $source, ?array $choices): PromotionPlan
    {
        $next = $source->getNextClass() ?? throw new \LogicException('A passage starts from a class that names its next class.');
        $targetYear = $next->getSchoolYear();
        $targets = [];
        foreach ($this->classes->findByYear($targetYear) as $class) {
            $targets[(int) $class->getId()] = $class;
        }

        $enrolments = $this->enrolments->findByClass($source);
        $students = array_map(static fn ($e): User => $e->getStudent(), $enrolments);
        $already = $this->enrolments->findByStudentsAndYear(array_map(static fn (User $s): int => (int) $s->getId(), $students), $targetYear);

        $lines = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $choice = null === $choices ? PromotionChoice::next() : ($choices[$id] ?? PromotionChoice::none());
            $target = match ($choice->kind) {
                PromotionChoice::NEXT => $next,
                PromotionChoice::NONE, PromotionChoice::DEPARTED => null,
                default => $targets[(int) $choice->classId] ?? null,
            };
            if (null === $target && !\in_array($choice->kind, [PromotionChoice::NONE, PromotionChoice::DEPARTED], true)) {
                // A class id that is not of the new year: the student is not placed.
                $choice = PromotionChoice::none();
            }
            $lines[] = new PromotionLine($student, $choice, $target, $already[$id] ?? null);
        }

        return new PromotionPlan($source, $next, $lines);
    }

    /** Applies the plan: enrolments of origin « passage de classe », leavers marked. */
    public function apply(PromotionPlan $plan, User $actor, \DateTimeImmutable $at): void
    {
        $byClass = [];
        $leavers = [];
        foreach ($plan->lines as $line) {
            if ($line->isAlreadyEnrolled()) {
                continue;
            }
            if (null !== $line->target) {
                $byClass[(int) $line->target->getId()]['class'] = $line->target;
                $byClass[(int) $line->target->getId()]['students'][] = $line->student;
            } elseif (PromotionChoice::DEPARTED === $line->choice->kind) {
                $leavers[] = $line->student;
            }
        }
        $this->entityManager->wrapInTransaction(function () use ($byClass, $leavers, $actor, $at): void {
            foreach ($byClass as $group) {
                $this->placements->place($group['students'], $group['class'], $actor, $at, EnrolmentOrigin::Promotion);
            }
            if ([] !== $leavers) {
                $this->placements->markDeparted($leavers, $actor, $at);
            }
        });
    }

    /** The year a passage leads into. */
    public function targetYear(SchoolClass $source): SchoolYear
    {
        return ($source->getNextClass() ?? throw new \LogicException('No next class.'))->getSchoolYear();
    }
}
