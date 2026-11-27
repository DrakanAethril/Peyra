<?php

declare(strict_types=1);

namespace App\Service\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\Enrolment;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierMemberRepository;
use App\Repository\Identity\SchoolYearRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The facts DossierVoter decides on (conception § 3 bis): who holds a student's or a group's
 * dossier, and whether a teacher teaches one of them in a class of the current year. Global reads
 * only (accounts, classes, members): the voter runs before any dossier context exists.
 */
final readonly class DossierHolders
{
    public function __construct(
        private DossierMemberRepository $members,
        private SchoolYearRepository $years,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<User> the student of a student's dossier, the members of a group's; nobody for a template */
    public function of(Dossier $dossier): array
    {
        return match ($dossier->getKind()) {
            DossierKind::Student => null === $dossier->getOwner() ? [] : [$dossier->getOwner()],
            DossierKind::Group => $this->members->findStudents($dossier),
            DossierKind::Template, DossierKind::Snapshot => [],
        };
    }

    /**
     * « Les enseignants de sa classe de l'année lisent tous ses dossiers, toutes années »: the
     * teacher teaches, this school year, a class one of these students is enrolled in.
     *
     * @param list<User> $students
     */
    public function teachesThisYear(User $teacher, array $students): bool
    {
        $year = $this->years->findCurrent();
        if (null === $year || [] === $students) {
            return false;
        }
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(TeacherClass::class, 't')
            ->join(Enrolment::class, 'e', 'WITH', 'e.schoolClass = t.schoolClass')
            ->andWhere('t.teacher = :teacher')
            ->andWhere('e.schoolYear = :year')
            ->andWhere('e.student IN (:students)')
            ->setParameter('teacher', $teacher)
            ->setParameter('year', $year)
            ->setParameter('students', $students)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
