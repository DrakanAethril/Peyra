<?php

declare(strict_types=1);

namespace App\Repository\Banking;

use App\Entity\Accounting\Journal;
use App\Entity\Banking\BankStatement;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The bank statements are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<BankStatement>
 */
final class BankStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankStatement::class);
    }

    /** @return list<BankStatement> by journal, the latest first */
    public function findLatestFirst(Dossier $dossier, ?Journal $journal = null): array
    {
        $criteria = ['dossier' => $dossier];
        if (null !== $journal) {
            $criteria['journal'] = $journal;
        }

        return $this->findBy($criteria, ['endsOn' => 'DESC', 'number' => 'DESC']);
    }

    /** The journal's statement with the highest number; null when it has none. */
    public function findLast(Journal $journal): ?BankStatement
    {
        return $this->findOneBy(['journal' => $journal], ['number' => 'DESC']);
    }

    /** The statement just before this one in its journal's numbering. */
    public function findPrevious(BankStatement $statement): ?BankStatement
    {
        $found = $this->createQueryBuilder('s')
            ->andWhere('s.journal = :journal')->setParameter('journal', $statement->getJournal())
            ->andWhere('s.number < :number')->setParameter('number', $statement->getNumber())
            ->orderBy('s.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $found instanceof BankStatement ? $found : null;
    }

    /** The statements after this one in its journal's numbering, nearest first. */
    public function findNext(BankStatement $statement): ?BankStatement
    {
        $found = $this->createQueryBuilder('s')
            ->andWhere('s.journal = :journal')->setParameter('journal', $statement->getJournal())
            ->andWhere('s.number > :number')->setParameter('number', $statement->getNumber())
            ->orderBy('s.number', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $found instanceof BankStatement ? $found : null;
    }

    public function findOneByNumber(Journal $journal, int $number): ?BankStatement
    {
        return $this->findOneBy(['journal' => $journal, 'number' => $number]);
    }
}
