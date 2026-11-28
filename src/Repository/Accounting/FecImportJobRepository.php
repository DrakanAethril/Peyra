<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\FecImportJob;
use App\Enum\Accounting\FecImportState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FecImportJob> */
final class FecImportJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FecImportJob::class);
    }

    /** @return list<int> the imports with passes still to run, the oldest first */
    public function findPendingIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('j')
            ->select('j.id')
            ->andWhere('j.state = :pending')
            ->setParameter('pending', FecImportState::Pending)
            ->orderBy('j.id', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }
}
