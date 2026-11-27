<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierDateChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DossierDateChange> */
final class DossierDateChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierDateChange::class);
    }

    /** @return list<DossierDateChange> the latest moves of the dossier's date, the most recent first */
    public function findLatest(Dossier $dossier, int $limit): array
    {
        /* @var list<DossierDateChange> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('c.changedAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
