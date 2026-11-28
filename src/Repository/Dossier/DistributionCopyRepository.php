<?php

declare(strict_types=1);

namespace App\Repository\Dossier;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Enum\Dossier\DistributionCopyState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DistributionCopy> */
final class DistributionCopyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DistributionCopy::class);
    }

    /** @return list<DistributionCopy> a distribution's dossiers, in the order they were asked */
    public function findByDistribution(Distribution $distribution): array
    {
        /* @var list<DistributionCopy> */
        return $this->createQueryBuilder('c')
            ->leftJoin('c.student', 's')
            ->addSelect('s')
            ->leftJoin('c.dossier', 'd')
            ->addSelect('d')
            ->andWhere('c.distribution = :distribution')
            ->setParameter('distribution', $distribution)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<int> the copies still to make (waiting, or left half way), oldest first - of one distribution or of all */
    public function findPendingIds(?Distribution $distribution = null): array
    {
        $query = $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.state IN (:pending)')
            ->setParameter('pending', [DistributionCopyState::Waiting, DistributionCopyState::Copying])
            ->orderBy('c.id', 'ASC');
        if (null !== $distribution) {
            $query->andWhere('c.distribution = :distribution')->setParameter('distribution', $distribution);
        }
        /** @var list<array{id: int|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * How many copies of each distribution stand in each state.
     *
     * @param list<Distribution> $distributions
     *
     * @return array<int, array<string, int>> by distribution id, then by state value
     */
    public function countByState(array $distributions): array
    {
        if ([] === $distributions) {
            return [];
        }
        /** @var list<array{distribution: int|string, state: DistributionCopyState|string, copies: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.distribution) AS distribution, c.state AS state, COUNT(c.id) AS copies')
            ->andWhere('c.distribution IN (:distributions)')
            ->setParameter('distributions', $distributions)
            ->groupBy('c.distribution, c.state')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $state = $row['state'] instanceof DistributionCopyState ? $row['state']->value : $row['state'];
            $counts[(int) $row['distribution']][$state] = (int) $row['copies'];
        }

        return $counts;
    }
}
