<?php

declare(strict_types=1);

namespace App\Repository\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesSequence;
use App\Enum\Sales\SalesDocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SalesSequence> */
final class SalesSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesSequence::class);
    }

    /** The sequence's row, locked until the transaction ends (SELECT … FOR UPDATE); null when none was opened yet. */
    public function findLocked(Dossier $dossier, SalesDocumentType $type, int $year): ?SalesSequence
    {
        $sequence = $this->createQueryBuilder('s')
            ->andWhere('s.dossier = :dossier')
            ->andWhere('s.type = :type')
            ->andWhere('s.year = :year')
            ->setParameter('dossier', $dossier)
            ->setParameter('type', $type)
            ->setParameter('year', $year)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $sequence instanceof SalesSequence ? $sequence : null;
    }

    /** @return list<SalesSequence> */
    public function findByDossier(Dossier $dossier): array
    {
        return $this->findBy(['dossier' => $dossier], ['type' => 'ASC', 'year' => 'ASC']);
    }
}
