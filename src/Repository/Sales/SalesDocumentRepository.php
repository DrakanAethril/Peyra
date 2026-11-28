<?php

declare(strict_types=1);

namespace App\Repository\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Sales\SalesDocument;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Sales\SalesDocumentState;
use App\Enum\Sales\SalesDocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The commercial documents are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<SalesDocument>
 */
final class SalesDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesDocument::class);
    }

    /**
     * A list's documents: drafts first (newest first), then the validated ones by number, newest first.
     *
     * @param list<SalesDocumentType> $types
     *
     * @return list<SalesDocument>
     */
    public function findOfTypes(Dossier $dossier, array $types): array
    {
        /** @var list<SalesDocument> $documents */
        $documents = $this->createQueryBuilder('d')
            ->addSelect('c')
            ->join('d.customer', 'c')
            ->andWhere('d.dossier = :dossier')
            ->andWhere('d.type IN (:types)')
            ->setParameter('dossier', $dossier)
            ->setParameter('types', $types)
            ->orderBy('d.state', 'ASC')
            ->addOrderBy('d.date', 'DESC')
            ->addOrderBy('d.number', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /** @return list<SalesDocument> the documents made from this one, oldest first */
    public function findMadeFrom(SalesDocument $source): array
    {
        return $this->findBy(['source' => $source], ['id' => 'ASC']);
    }

    /** @return list<SalesDocument> the validated credit notes of an invoice */
    public function findValidatedCreditNotesOf(SalesDocument $invoice): array
    {
        return $this->findBy(['source' => $invoice, 'type' => SalesDocumentType::CreditNote, 'state' => SalesDocumentState::Validated], ['id' => 'ASC']);
    }

    public function countForCustomer(ThirdParty $customer): int
    {
        return $this->count(['customer' => $customer]);
    }
}
