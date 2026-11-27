<?php

declare(strict_types=1);

namespace App\Repository\Audit;

use App\Entity\Audit\AuditEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEvent> */
final class AuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    /** @return list<AuditEvent> the history of one subject, newest first */
    public function findForSubject(string $type, string $id, int $limit = 50): array
    {
        /* @var list<AuditEvent> */
        return $this->createQueryBuilder('e')
            ->addSelect('a')
            ->leftJoin('e.actor', 'a')
            ->andWhere('e.subjectType = :type')
            ->andWhere('e.subjectId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $id)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
