<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SchoolYear> */
final class SchoolYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolYear::class);
    }

    public function findCurrent(): ?SchoolYear
    {
        return $this->findOneBy(['current' => true]);
    }

    /** @return list<SchoolYear> the most recent first */
    public function findAllNewestFirst(): array
    {
        /* @var list<SchoolYear> */
        return $this->createQueryBuilder('y')->orderBy('y.startsOn', 'DESC')->getQuery()->getResult();
    }

    /** The year that ends last before this one starts. */
    public function findPrevious(SchoolYear $year): ?SchoolYear
    {
        /* @var SchoolYear|null */
        return $this->createQueryBuilder('y')
            ->andWhere('y.startsOn < :start')
            ->setParameter('start', $year->getStartsOn())
            ->orderBy('y.startsOn', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<SchoolYear> the years before this one, the most recent first */
    public function findBefore(SchoolYear $year): array
    {
        /* @var list<SchoolYear> */
        return $this->createQueryBuilder('y')
            ->andWhere('y.startsOn < :start')
            ->setParameter('start', $year->getStartsOn())
            ->orderBy('y.startsOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** A year whose dates cross [start, end], other than `except`. */
    public function findOverlapping(\DateTimeImmutable $start, \DateTimeImmutable $end, ?SchoolYear $except = null): ?SchoolYear
    {
        $query = $this->createQueryBuilder('y')
            ->andWhere('y.startsOn <= :end AND y.endsOn >= :start')
            ->setParameter('start', $start, 'date_immutable')
            ->setParameter('end', $end, 'date_immutable')
            ->setMaxResults(1);
        if (null !== $except) {
            $query->andWhere('y != :except')->setParameter('except', $except);
        }

        /* @var SchoolYear|null */
        return $query->getQuery()->getOneOrNullResult();
    }
}
