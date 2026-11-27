<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\TeacherClass;
use App\Entity\Identity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<TeacherClass> */
final class TeacherClassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeacherClass::class);
    }

    /** @return list<TeacherClass> the class's teachers, by name */
    public function findByClass(SchoolClass $class): array
    {
        /* @var list<TeacherClass> */
        return $this->createQueryBuilder('t')
            ->join('t.teacher', 'u')
            ->addSelect('u')
            ->andWhere('t.schoolClass = :class')
            ->setParameter('class', $class)
            ->orderBy('u.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<TeacherClass> the teachers of every class of a year */
    public function findByYear(SchoolYear $year): array
    {
        /* @var list<TeacherClass> */
        return $this->createQueryBuilder('t')
            ->join('t.teacher', 'u')
            ->join('t.schoolClass', 'c')
            ->addSelect('u', 'c')
            ->andWhere('c.schoolYear = :year')
            ->setParameter('year', $year)
            ->orderBy('u.sortName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<int> ids of the classes of a year this teacher teaches */
    public function findClassIdsOf(User $teacher, SchoolYear $year): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.schoolClass) AS id')
            ->join('t.schoolClass', 'c')
            ->andWhere('t.teacher = :teacher')
            ->andWhere('c.schoolYear = :year')
            ->setParameter('teacher', $teacher)
            ->setParameter('year', $year)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function findOneByTeacherAndClass(User $teacher, SchoolClass $class): ?TeacherClass
    {
        return $this->findOneBy(['teacher' => $teacher, 'schoolClass' => $class]);
    }
}
