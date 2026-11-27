<?php

declare(strict_types=1);

namespace App\Repository\Identity;

use App\Entity\Identity\AdministrationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdministrationEvent> */
final class AdministrationEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdministrationEvent::class);
    }

    /** @return list<AdministrationEvent> newest first */
    public function findPage(int $page, int $perPage): array
    {
        /** @var list<AdministrationEvent> */
        return $this->createQueryBuilder('e')
            ->addSelect('a')
            ->join('e.actor', 'a')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return $this->count();
    }
}
