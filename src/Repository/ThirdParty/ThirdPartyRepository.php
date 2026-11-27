<?php

declare(strict_types=1);

namespace App\Repository\ThirdParty;

use App\Entity\Accounting\Account;
use App\Entity\Dossier\Dossier;
use App\Entity\ThirdParty\ThirdParty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Third parties are DossierOwned: every method reads inside the current dossier's context
 * (DossierContext), and throws outside one.
 *
 * @extends ServiceEntityRepository<ThirdParty>
 */
final class ThirdPartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThirdParty::class);
    }

    /** @return list<ThirdParty> the dossier's third parties, by code, their accounts loaded */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<ThirdParty> */
        return $this->createQueryBuilder('t')
            ->addSelect('a')
            ->leftJoin('t.account', 'a')
            ->andWhere('t.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(Dossier $dossier, string $code): ?ThirdParty
    {
        /* @var ThirdParty|null */
        return $this->createQueryBuilder('t')
            ->andWhere('t.dossier = :dossier')
            ->andWhere('t.code = :code')
            ->setParameter('dossier', $dossier)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByAuxiliaryCode(Dossier $dossier, string $auxiliaryCode): ?ThirdParty
    {
        /* @var ThirdParty|null */
        return $this->createQueryBuilder('t')
            ->andWhere('t.dossier = :dossier')
            ->andWhere('t.auxiliaryCode = :auxiliary')
            ->setParameter('dossier', $dossier)
            ->setParameter('auxiliary', $auxiliaryCode)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ThirdParty> the third parties kept on that account, by code */
    public function findByAccount(Account $account): array
    {
        /* @var list<ThirdParty> */
        return $this->createQueryBuilder('t')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many third parties each account of the plan carries, by account id.
     *
     * @return array<int, int>
     */
    public function countByAccount(Dossier $dossier): array
    {
        /** @var list<array{account: int|string, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.account) AS account, COUNT(t.id) AS n')
            ->andWhere('t.dossier = :dossier')
            ->andWhere('t.account IS NOT NULL')
            ->setParameter('dossier', $dossier)
            ->groupBy('t.account')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['account']] = (int) $row['n'];
        }

        return $counts;
    }
}
