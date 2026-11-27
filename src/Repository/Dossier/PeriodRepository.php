<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use App\Entity\Dossier\Period;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Periods are DossierOwned: read inside the dossier's context (DossierContext).
 *
 * @extends ServiceEntityRepository<Period>
 */
final class PeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Period::class);
    }

    /** The period a day falls in, or null when no exercise holds it. */
    public function findAt(Dossier $dossier, \DateTimeImmutable $day): ?Period
    {
        /* @var Period|null */
        return $this->createQueryBuilder('p')
            ->join('p.exercise', 'e')
            ->addSelect('e')
            ->andWhere('p.dossier = :dossier')
            ->andWhere('p.startsOn <= :day')
            ->andWhere('p.endsOn >= :day')
            ->setParameter('dossier', $dossier)
            ->setParameter('day', $day->format('Y-m-d'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Period> an exercise's periods, in order */
    public function findByExercise(Exercise $exercise): array
    {
        /* @var list<Period> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.exercise = :exercise')
            ->setParameter('exercise', $exercise)
            ->orderBy('p.startsOn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Period> the dossier's periods, in order */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<Period> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('p.startsOn', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
