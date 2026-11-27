<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\Enrolment;
use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Enrolment> */
final class EnrolmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enrolment::class);
    }

    public function findOneForStudentAndYear(User $student, SchoolYear $year): ?Enrolment
    {
        return $this->findOneBy(['student' => $student, 'schoolYear' => $year]);
    }

    /** @return list<Enrolment> the class's students, « Nom Prénom » order */
    public function findByClass(SchoolClass $class): array
    {
        /* @var list<Enrolment> */
        return $this->createQueryBuilder('e')
            ->join('e.student', 's')
            ->addSelect('s')
            ->andWhere('e.schoolClass = :class')
            ->setParameter('class', $class)
            ->orderBy('s.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Enrolment> a student's enrolments, the most recent year first */
    public function findByStudent(User $student): array
    {
        /* @var list<Enrolment> */
        return $this->createQueryBuilder('e')
            ->join('e.schoolYear', 'y')
            ->join('e.schoolClass', 'c')
            ->addSelect('y', 'c')
            ->andWhere('e.student = :student')
            ->setParameter('student', $student)
            ->orderBy('y.startsOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The enrolments of these students in that year, by student id.
     *
     * @param list<int> $studentIds
     *
     * @return array<int, Enrolment>
     */
    public function findByStudentsAndYear(array $studentIds, SchoolYear $year): array
    {
        if ([] === $studentIds) {
            return [];
        }
        /** @var list<Enrolment> $rows */
        $rows = $this->createQueryBuilder('e')
            ->join('e.schoolClass', 'c')
            ->addSelect('c')
            ->andWhere('e.schoolYear = :year')
            ->andWhere('e.student IN (:students)')
            ->setParameter('year', $year)
            ->setParameter('students', $studentIds)
            ->getQuery()
            ->getResult();
        $byStudent = [];
        foreach ($rows as $row) {
            $byStudent[(int) $row->getStudent()->getId()] = $row;
        }

        return $byStudent;
    }

    /**
     * The last enrolment of each of these students, whatever its year, by student id: the « dernière
     * classe connue » of « À placer ».
     *
     * @param list<int> $studentIds
     *
     * @return array<int, Enrolment>
     */
    public function findLatestByStudents(array $studentIds): array
    {
        if ([] === $studentIds) {
            return [];
        }
        /** @var list<Enrolment> $rows */
        $rows = $this->createQueryBuilder('e')
            ->join('e.schoolYear', 'y')
            ->join('e.schoolClass', 'c')
            ->addSelect('y', 'c')
            ->andWhere('e.student IN (:students)')
            ->setParameter('students', $studentIds)
            ->orderBy('y.startsOn', 'ASC')
            ->getQuery()
            ->getResult();
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->getStudent()->getId()] = $row;
        }

        return $latest;
    }

    /** @return array<int, int> number of students per class id, for the classes of a year */
    public function countByClassInYear(SchoolYear $year): array
    {
        /** @var list<array{classId: int|string, students: int|string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('IDENTITY(e.schoolClass) AS classId', 'COUNT(e.id) AS students')
            ->andWhere('e.schoolYear = :year')
            ->setParameter('year', $year)
            ->groupBy('e.schoolClass')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['classId']] = (int) $row['students'];
        }

        return $counts;
    }

    public function countByClass(SchoolClass $class): int
    {
        return $this->count(['schoolClass' => $class]);
    }
}
