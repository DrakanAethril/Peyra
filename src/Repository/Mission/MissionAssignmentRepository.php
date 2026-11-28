<?php

declare(strict_types=1);

namespace App\Repository\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Dossier\DistributionCopy;
use App\Entity\Dossier\Dossier;
use App\Entity\Mission\Mission;
use App\Entity\Mission\MissionAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MissionAssignment> */
final class MissionAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MissionAssignment::class);
    }

    /** @return list<MissionAssignment> the distributions a mission was given to, the latest first */
    public function findByMission(Mission $mission): array
    {
        return $this->findBy(['mission' => $mission], ['givenAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findOneFor(Mission $mission, Distribution $distribution): ?MissionAssignment
    {
        return $this->findOneBy(['mission' => $mission, 'distribution' => $distribution]);
    }

    /** « Mission 3 »: the rank of a giving among the missions given to its distribution, by date. */
    public function rankOf(MissionAssignment $assignment): int
    {
        $count = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.distribution = :distribution')->setParameter('distribution', $assignment->getDistribution())
            ->andWhere('a.givenAt < :at OR (a.givenAt = :at AND a.id <= :id)')
            ->setParameter('at', $assignment->getGivenAt())->setParameter('id', $assignment->getId())
            ->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 1;
    }

    /** The mission a dossier plays now: the latest given to the distribution that made it. */
    public function findCurrentFor(Dossier $dossier): ?MissionAssignment
    {
        /* @var MissionAssignment|null */
        return $this->createQueryBuilder('a')
            ->addSelect('m')->join('a.mission', 'm')
            ->join(DistributionCopy::class, 'c', 'WITH', 'c.distribution = a.distribution')
            ->andWhere('c.dossier = :dossier')->setParameter('dossier', $dossier)
            ->orderBy('a.givenAt', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
