<?php

declare(strict_types=1);

namespace App\Repository\Regulatory;

use App\Entity\Regulatory\RegulatoryValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The dated values, always in the reading order: key, effective date, then the order they were
 * written in - so that, for one key, the last row of a date is the one in force at that date.
 *
 * @extends ServiceEntityRepository<RegulatoryValue>
 */
final class RegulatoryValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegulatoryValue::class);
    }

    /** @return list<RegulatoryValue> */
    public function findAllInReadingOrder(): array
    {
        /* @var list<RegulatoryValue> */
        return $this->ordered()->getQuery()->getResult();
    }

    /** @return list<RegulatoryValue> every row of one key, its corrections included */
    public function findForKey(string $key): array
    {
        /* @var list<RegulatoryValue> */
        return $this->ordered()
            ->andWhere('v.key = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getResult();
    }

    /** @return list<RegulatoryValue> the rows that took effect on that day or before */
    public function findEffectiveOn(\DateTimeImmutable $day): array
    {
        /* @var list<RegulatoryValue> */
        return $this->ordered()
            ->andWhere('v.effectiveFrom <= :day')
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    private function ordered(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('v')
            ->addSelect('u')
            ->leftJoin('v.recordedBy', 'u')
            ->orderBy('v.key', 'ASC')
            ->addOrderBy('v.effectiveFrom', 'ASC')
            ->addOrderBy('v.id', 'ASC');
    }
}
