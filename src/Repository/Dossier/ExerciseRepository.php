<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\Exercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Exercises are DossierOwned: every method reads inside the current dossier's context
 * (DossierContext), and throws outside one.
 *
 * @extends ServiceEntityRepository<Exercise>
 */
final class ExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /** @return list<Exercise> the dossier's exercises, the most recent first */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<Exercise> */
        return $this->createQueryBuilder('e')
            ->andWhere('e.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('e.startsOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** The exercise a day falls in, or null. */
    public function findAt(Dossier $dossier, \DateTimeImmutable $day): ?Exercise
    {
        /* @var Exercise|null */
        return $this->createQueryBuilder('e')
            ->andWhere('e.dossier = :dossier')
            ->andWhere('e.startsOn <= :day')
            ->andWhere('e.endsOn >= :day')
            ->setParameter('dossier', $dossier)
            ->setParameter('day', $day->format('Y-m-d'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * For each of several dossiers, the exercise its date falls in (« Mes dossiers », the switcher),
     * in one query. Read inside DossierContext::across() over the same dossiers.
     *
     * @param list<Dossier> $dossiers
     *
     * @return array<int, Exercise> by dossier id
     */
    public function findCurrentOf(array $dossiers): array
    {
        if ([] === $dossiers) {
            return [];
        }
        /** @var list<Exercise> $exercises */
        $exercises = $this->createQueryBuilder('e')
            ->join('e.dossier', 'd')
            ->andWhere('e.dossier IN (:dossiers)')
            ->andWhere('e.startsOn <= d.dossierDate')
            ->andWhere('e.endsOn >= d.dossierDate')
            ->setParameter('dossiers', $dossiers)
            ->getQuery()
            ->getResult();
        $byDossier = [];
        foreach ($exercises as $exercise) {
            $byDossier[(int) $exercise->getDossier()->getId()] = $exercise;
        }

        return $byDossier;
    }

    /** One of the dossier's exercises sharing a day with these bounds, or null. */
    public function findOverlapping(Dossier $dossier, \DateTimeImmutable $start, \DateTimeImmutable $end): ?Exercise
    {
        /* @var Exercise|null */
        return $this->createQueryBuilder('e')
            ->andWhere('e.dossier = :dossier')
            ->andWhere('e.startsOn <= :end')
            ->andWhere('e.endsOn >= :start')
            ->setParameter('dossier', $dossier)
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
