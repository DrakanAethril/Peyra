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

    /** @return list<Document> the second arrivals of a pièce (its « doublon », step 2.2b) */
    public function findDuplicatesOf(Document $first): array
    {
        /* @var list<Document> */
        return $this->createQueryBuilder('d')
            ->andWhere('d.dossier = :dossier')
            ->andWhere('d.duplicateOf = :first')
            ->setParameter('dossier', $first->getDossier())
            ->setParameter('first', $first)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The rail's counter: the pièces still to process (« à traiter » and « en cours »). */
    public function countToProcess(Dossier $dossier): int
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.dossier = :dossier')
            ->andWhere('d.state IN (:states)')
            ->setParameter('dossier', $dossier)
            ->setParameter('states', [DocumentState::ToProcess, DocumentState::InProgress])
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    /** The pièce F10 opens after this one (design-ux.md § 4.3): the next one still to process in the inbox's order, else the first. */
    public function findNextOpen(Dossier $dossier, Document $after): ?Document
    {
        $open = array_values(array_filter($this->findByDossier($dossier), static fn (Document $one): bool => $one !== $after && $one->getState()->isOpen()));
        $all = $this->findByDossier($dossier);
        $position = array_search($after, $all, true);
        foreach ($open as $candidate) {
            if (false !== $position && array_search($candidate, $all, true) > $position) {
                return $candidate;
            }
        }

        return $open[0] ?? null;
    }
}
