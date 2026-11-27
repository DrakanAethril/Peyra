<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Enum\Accounting\JournalType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Journals are DossierOwned: every method reads inside the current dossier's context
 * (DossierContext), and throws outside one.
 *
 * @extends ServiceEntityRepository<Journal>
 */
final class JournalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Journal::class);
    }

    /** @return list<Journal> by code */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<Journal> */
        return $this->createQueryBuilder('j')
            ->leftJoin('j.counterpartAccount', 'a')
            ->addSelect('a')
            ->andWhere('j.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('j.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(Dossier $dossier, string $code): ?Journal
    {
        /* @var Journal|null */
        return $this->createQueryBuilder('j')
            ->andWhere('j.dossier = :dossier')
            ->andWhere('j.code = :code')
            ->setParameter('dossier', $dossier)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Journal> the journals of a type (the à-nouveaux journal is one at most) */
    public function findByType(Dossier $dossier, JournalType $type): array
    {
        /* @var list<Journal> */
        return $this->createQueryBuilder('j')
            ->andWhere('j.dossier = :dossier')
            ->andWhere('j.type = :type')
            ->setParameter('dossier', $dossier)
            ->setParameter('type', $type)
            ->orderBy('j.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Journal> the journals whose counterpart this account is */
    public function findByCounterpart(Account $account): array
    {
        /* @var list<Journal> */
        return $this->createQueryBuilder('j')
            ->andWhere('j.counterpartAccount = :account')
            ->setParameter('account', $account)
            ->orderBy('j.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
