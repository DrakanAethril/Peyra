<?php

declare(strict_types=1);

namespace App\Service\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Identity\AdministrationAction;
use App\Enum\Identity\ClassLevel;
use App\Repository\Identity\EnrolmentRepository;
use App\Repository\Identity\SchoolClassRepository;
use App\Repository\Identity\TeacherClassRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Administration › Classes (design-ux.md § 2.6): a year's classes, their next class and their
 * teachers. Every gesture goes to the administration journal; a refusal comes back as the
 * sentence to show. A class that has had students is never deleted.
 */
final readonly class SchoolClasses
{
    private const int NAME_MAX = 80;

    public function __construct(
        private SchoolClassRepository $classes,
        private EnrolmentRepository $enrolments,
        private TeacherClassRepository $teachers,
        private AdministrationJournal $journal,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return SchoolClass|string the class, or the reason it was not created */
    public function create(SchoolYear $year, string $name, ?ClassLevel $level, User $actor, \DateTimeImmutable $at): SchoolClass|string
    {
        $refusal = $this->nameRefusal($year, $name, $level, null);
        if (null !== $refusal) {
            return $refusal;
        }
        \assert(null !== $level);
        $class = new SchoolClass($year, trim($name), $level, $at);
        $this->entityManager->persist($class);
        $this->journal->record($at, $actor, AdministrationAction::ClassCreated, $class->getLabel(), $class->getLabel(), $level->label());
        $this->entityManager->flush();

        return $class;
    }

    /** @return string|null the refusal, or null once saved */
    public function update(SchoolClass $class, string $name, ?ClassLevel $level, ?SchoolClass $next, User $actor, \DateTimeImmutable $at): ?string
    {
        $refusal = $this->nameRefusal($class->getSchoolYear(), $name, $level, $class);
        if (null !== $refusal) {
            return $refusal;
        }
        \assert(null !== $level);
        if (null !== $next && $next->getSchoolYear()->getStartsOn() <= $class->getSchoolYear()->getStartsOn()) {
            return 'La classe suivante appartient à une année qui commence après celle-ci.';
        }

        $changes = [];
        if (trim($name) !== $class->getName() || $level !== $class->getLevel()) {
            $changes[] = trim($name).', '.$level->label();
        }
        if ($next !== $class->getNextClass()) {
            $changes[] = 'Classe suivante : '.($next?->getLabel() ?? 'aucune');
        }
        if ([] === $changes) {
            return null;
        }
        $class->rename(trim($name), $level);
        $class->setNextClass($next);
        $this->journal->record($at, $actor, AdministrationAction::ClassUpdated, $class->getLabel(), $class->getLabel(), implode(' · ', $changes));
        $this->entityManager->flush();

        return null;
    }

    /** Whether a class may be deleted: it never had a student (its enrolments are the history). */
    public function deletionRefusal(SchoolClass $class): ?string
    {
        return $this->enrolments->countByClass($class) > 0
            ? 'Une classe qui a des étudiants ne se supprime pas.'
            : null;
    }

    public function delete(SchoolClass $class, User $actor, \DateTimeImmutable $at): ?string
    {
        $refusal = $this->deletionRefusal($class);
        if (null !== $refusal) {
            return $refusal;
        }
        foreach ($this->classes->findLeadingTo($class) as $previous) {
            $previous->setNextClass(null);
        }
        $this->journal->record($at, $actor, AdministrationAction::ClassDeleted, $class->getLabel(), $class->getLabel());
        $this->entityManager->remove($class);
        $this->entityManager->flush();

        return null;
    }

    public function addTeacher(SchoolClass $class, User $teacher, User $actor, \DateTimeImmutable $at): ?string
    {
        if (!$teacher->isTeacher()) {
            return 'Choisissez un enseignant dans la liste.';
        }
        if (null !== $this->teachers->findOneByTeacherAndClass($teacher, $class)) {
            return \sprintf('%s enseigne déjà dans cette classe.', $teacher->getDisplayName());
        }
        $this->entityManager->persist(new TeacherClass($teacher, $class, $at, $actor));
        $this->journal->record($at, $actor, AdministrationAction::ClassTeacherAdded, $teacher->getLogin(), $teacher->getDisplayName(), $class->getLabel());
        $this->entityManager->flush();

        return null;
    }

    public function removeTeacher(TeacherClass $assignment, User $actor, \DateTimeImmutable $at): void
    {
        $teacher = $assignment->getTeacher();
        $this->journal->record($at, $actor, AdministrationAction::ClassTeacherRemoved, $teacher->getLogin(), $teacher->getDisplayName(), $assignment->getSchoolClass()->getLabel());
        $this->entityManager->remove($assignment);
        $this->entityManager->flush();
    }

    private function nameRefusal(SchoolYear $year, string $name, ?ClassLevel $level, ?SchoolClass $except): ?string
    {
        $name = trim($name);
        if ('' === $name) {
            return 'Donnez un nom à la classe.';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return \sprintf('Le nom tient en %d caractères.', self::NAME_MAX);
        }
        if (null === $level) {
            return 'Choisissez l’année du BTS que prépare la classe.';
        }
        $same = $this->classes->findOneByYearAndName($year, $name);
        if (null !== $same && $same !== $except) {
            return \sprintf('L’année %s a déjà une classe « %s ».', $year->getLabel(), $name);
        }

        return null;
    }
}
