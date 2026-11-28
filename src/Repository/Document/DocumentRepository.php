<?php

declare(strict_types=1);

namespace App\Repository\Document;

use App\Entity\Document\Document;
use App\Entity\Dossier\Dossier;
use App\Enum\Document\DocumentState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Pièces are DossierOwned: every method reads inside the current dossier's context
 * (DossierContext), and throws outside one.
 *
 * @extends ServiceEntityRepository<Document>
 */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /** @return list<Document> the dossier's pièces, the latest arrived first, their third parties loaded */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<Document> */
        return $this->createQueryBuilder('d')
            ->addSelect('t')
            ->leftJoin('d.thirdParty', 't')
            ->andWhere('d.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('d.receivedOn', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneInDossier(Dossier $dossier, int $id): ?Document
    {
        /* @var Document|null */
        return $this->createQueryBuilder('d')
            ->andWhere('d.dossier = :dossier')
            ->andWhere('d.id = :id')
            ->setParameter('dossier', $dossier)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The rail's counter: the pièces still to process. */
    public function countToProcess(Dossier $dossier): int
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.dossier = :dossier')
            ->andWhere('d.state = :state')
            ->setParameter('dossier', $dossier)
            ->setParameter('state', DocumentState::ToProcess)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }
}
