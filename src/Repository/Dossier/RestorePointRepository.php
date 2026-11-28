<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
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

    /** The points that count against a dossier's limit (design-ux.md § 4.9): every one, whatever its state. */
    public function countByDossier(Dossier $dossier): int
    {
        return $this->count(['dossier' => $dossier]);
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
}
