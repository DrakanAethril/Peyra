<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Dossier\RestorePointState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RestorePoint> */
final class RestorePointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RestorePoint::class);
    }

    /** @return list<RestorePoint> a dossier's points, the newest first */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<RestorePoint> */
        return $this->createQueryBuilder('p')
            ->leftJoin('p.author', 'a')
            ->addSelect('a')
            ->andWhere('p.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Every point of a dossier, whatever its state and origin. */
    public function countByDossier(Dossier $dossier): int
    {
        return $this->count(['dossier' => $dossier]);
    }

    /**
     * The points that count against a dossier's limit (design-ux.md § 4.9): every one, whatever its
     * state, but its « État de départ » (RestorePointOrigin::countsAgainstLimit()).
     */
    public function countAgainstLimit(Dossier $dossier): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.dossier = :dossier')
            ->andWhere('p.origin <> :start')
            ->setParameter('dossier', $dossier)
            ->setParameter('start', RestorePointOrigin::Start)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<RestorePoint> every point whose copy has not been made yet, the oldest first */
    public function findPreparing(): array
    {
        /* @var list<RestorePoint> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.state = :state')
            ->setParameter('state', RestorePointState::Preparing)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The points of several dossiers, by dossier id, each list the newest first (Classe ›
     * Dossiers: the last point and the « État de départ » of every row in one query).
     *
     * @param list<Dossier> $dossiers
     *
     * @return array<int, list<RestorePoint>>
     */
    public function findByDossiers(array $dossiers): array
    {
        if ([] === $dossiers) {
            return [];
        }
        /** @var list<RestorePoint> $points */
        $points = $this->createQueryBuilder('p')
            ->andWhere('p.dossier IN (:dossiers)')
            ->setParameter('dossiers', $dossiers)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
        $byDossier = [];
        foreach ($points as $point) {
            $byDossier[(int) $point->getDossier()->getId()][] = $point;
        }

        return $byDossier;
    }

    /** The « État de départ » a distribution posed on the dossier, when it is ready (the latest, should there be two). */
    public function findStart(Dossier $dossier): ?RestorePoint
    {
        return $this->findOneBy(['dossier' => $dossier, 'origin' => RestorePointOrigin::Start, 'state' => RestorePointState::Ready], ['id' => 'DESC']);
    }
}
