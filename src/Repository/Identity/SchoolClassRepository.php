<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SchoolClass> */
final class SchoolClassRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolClass::class);
    }

    /** @return list<SchoolClass> by level, then name */
    public function findByYear(SchoolYear $year): array
    {
        /* @var list<SchoolClass> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.schoolYear = :year')
            ->setParameter('year', $year)
            ->orderBy('c.level', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Classes whose next class belongs to `year`: the sources of a « passage de classe » into it.
     *
     * @return list<SchoolClass>
     */
    public function findLeadingInto(SchoolYear $year): array
    {
        /* @var list<SchoolClass> */
        return $this->createQueryBuilder('c')
            ->join('c.nextClass', 'n')
            ->join('c.schoolYear', 'y')
            ->addSelect('n', 'y')
            ->andWhere('n.schoolYear = :year')
            ->setParameter('year', $year)
            ->orderBy('y.startsOn', 'DESC')
            ->addOrderBy('c.level', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SchoolClass> the classes naming this one as their next class */
    public function findLeadingTo(SchoolClass $class): array
    {
        /* @var list<SchoolClass> */
        return $this->findBy(['nextClass' => $class], ['name' => 'ASC']);
    }

    public function findOneByYearAndName(SchoolYear $year, string $name): ?SchoolClass
    {
        return $this->findOneBy(['schoolYear' => $year, 'name' => $name]);
    }
}
