<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\EntryTemplate;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Entry templates are DossierOwned: every method reads inside the current dossier's context.
 *
 * @extends ServiceEntityRepository<EntryTemplate>
 */
final class EntryTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryTemplate::class);
    }

    /** @return list<EntryTemplate> by code, their journal and lines loaded */
    public function findByDossier(Dossier $dossier, ?Journal $journal = null): array
    {
        $query = $this->createQueryBuilder('t')
            ->innerJoin('t.journal', 'j')
            ->addSelect('j')
            ->leftJoin('t.lines', 'l')
            ->addSelect('l')
            ->andWhere('t.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('t.code', 'ASC')
            ->addOrderBy('l.position', 'ASC');
        if (null !== $journal) {
            $query->andWhere('t.journal = :journal')->setParameter('journal', $journal);
        }

        /* @var list<EntryTemplate> */
        return $query->getQuery()->getResult();
    }

    public function findOneByCode(Dossier $dossier, string $code): ?EntryTemplate
    {
        /* @var EntryTemplate|null */
        return $this->createQueryBuilder('t')
            ->andWhere('t.dossier = :dossier')
            ->andWhere('t.code = :code')
            ->setParameter('dossier', $dossier)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countInJournal(Journal $journal): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.journal = :journal')
            ->setParameter('journal', $journal)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
