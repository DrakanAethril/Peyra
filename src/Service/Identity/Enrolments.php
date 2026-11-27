<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use App\Enum\Identity\EnrolmentOrigin;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one door of placement by hand (conception § 3 bis): place students in a class, move them,
 * take them out of a class, mark them as having left the field. Peyra never changes a class by
 * itself; every gesture here is a teacher's, and goes to the administration journal. Nothing here
 * touches an account or a dossier: a dossier is its student's, whatever their class.
 *
 * The classes of a year before the current one are history: nothing is placed there any more.
 */
final readonly class Enrolments
{
    public function __construct(
        private EnrolmentRepository $enrolments,
        private SchoolYearRepository $years,
        private AdministrationJournal $journal,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Whether the class belongs to a year before the current one, whose placements are history. */
    public function isHistory(SchoolClass $class): bool
    {
        $current = $this->years->findCurrent();

        return null !== $current && $class->getSchoolYear()->getStartsOn() < $current->getStartsOn();
    }

    /**
     * Places each student in the class for its year: a student without a class there is enrolled, a
     * student in another class of that year is moved, one already there is left as is. Placing
     * someone marked as having left brings them back.
     *
     * @param list<User> $students
     */
    public function place(array $students, SchoolClass $class, User $actor, \DateTimeImmutable $at, EnrolmentOrigin $origin = EnrolmentOrigin::Manual): PlacementResult
    {
        $this->refuseHistory($class);
        $year = $class->getSchoolYear();
        $existing = $this->enrolments->findByStudentsAndYear(self::ids($students), $year);
        $placed = $moved = $unchanged = 0;
        foreach ($students as $student) {
            if (!$student->isStudent()) {
                continue;
            }
            $student->clearDeparture();
            $enrolment = $existing[(int) $student->getId()] ?? null;
            if (null === $enrolment) {
                $this->entityManager->persist(new Enrolment($student, $class, $origin, $at, $actor));
                $this->journal->recordStudent($at, $actor, EnrolmentOrigin::Promotion === $origin ? AdministrationAction::StudentPromoted : AdministrationAction::StudentPlaced, $student, $class->getLabel());
                ++$placed;
            } elseif ($enrolment->getSchoolClass() === $class) {
                ++$unchanged;
            } else {
                $from = $enrolment->getSchoolClass();
                $enrolment->moveTo($class, $at, $actor);
                $this->journal->recordStudent($at, $actor, AdministrationAction::StudentMoved, $student, $from->getName().' → '.$class->getLabel());
                ++$moved;
            }
        }
        $this->entityManager->flush();

        return new PlacementResult($placed, $moved, $unchanged);
    }

    /**
     * Takes students out of their class for that year: they have no class in the year any more
     * and appear in « À placer » when the year is the current one. Accounts and dossiers stay.
     *
     * @param list<Enrolment> $enrolments
     */
    public function remove(array $enrolments, User $actor, \DateTimeImmutable $at): int
    {
        foreach ($enrolments as $enrolment) {
            $this->refuseHistory($enrolment->getSchoolClass());
            $this->journal->recordStudent($at, $actor, AdministrationAction::StudentRemoved, $enrolment->getStudent(), $enrolment->getSchoolClass()->getLabel());
            $this->entityManager->remove($enrolment);
        }
        $this->entityManager->flush();

        return \count($enrolments);
    }

    /**
     * Marks students as having left the field (« Sorti de la filière »): they stay out of « À
     * placer » and keep their account and their dossiers. A passage de classe prepared ahead marks
     * a leaver who is still in their class of the current year: they finish it.
     *
     * @param list<User> $students
     */
    public function markDeparted(array $students, User $actor, \DateTimeImmutable $at): int
    {
        $marked = 0;
        foreach ($students as $student) {
            if (!$student->isStudent() || $student->isDeparted()) {
                continue;
            }
            $student->markDeparted($at, $actor);
            $this->journal->recordStudent($at, $actor, AdministrationAction::StudentDeparted, $student);
            ++$marked;
        }
        $this->entityManager->flush();

        return $marked;
    }

    /** @param list<User> $students */
    public function restore(array $students, User $actor, \DateTimeImmutable $at): int
    {
        $restored = 0;
        foreach ($students as $student) {
            if (!$student->isDeparted()) {
                continue;
            }
            $student->clearDeparture();
            $this->journal->recordStudent($at, $actor, AdministrationAction::StudentReturned, $student);
            ++$restored;
        }
        $this->entityManager->flush();

        return $restored;
    }

    private function refuseHistory(SchoolClass $class): void
    {
        if ($this->isHistory($class)) {
            throw new \LogicException('The classes of a past year are history: nobody is placed there any more.');
        }
    }

    /**
     * @param list<User> $students
     *
     * @return list<int>
     */
    private static function ids(array $students): array
    {
        return array_values(array_filter(array_map(static fn (User $s): ?int => $s->getId(), $students), static fn (?int $id): bool => null !== $id));
    }
}
