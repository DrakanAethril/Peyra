<?php

declare(strict_types=1);

namespace App\Repository\Accounting;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accounts are DossierOwned: every method reads inside the current dossier's context
 * (DossierContext), and throws outside one.
 *
 * @extends ServiceEntityRepository<Account>
 */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /** @return list<Account> the dossier's plan, by number */
    public function findPlan(Dossier $dossier): array
    {
        /* @var list<Account> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('a.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNumber(Dossier $dossier, string $number): ?Account
    {
        /* @var Account|null */
        return $this->createQueryBuilder('a')
            ->andWhere('a.dossier = :dossier')
            ->andWhere('a.number = :number')
            ->setParameter('dossier', $dossier)
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPlan(Dossier $dossier): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
