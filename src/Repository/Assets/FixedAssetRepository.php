<?php

declare(strict_types=1);

namespace App\Repository\Assets;

use App\Entity\Accounting\Account;
use App\Entity\Assets\FixedAsset;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Fixed assets are DossierOwned: every method reads inside the current dossier's context
 * (DossierContext), and throws outside one.
 *
 * @extends ServiceEntityRepository<FixedAsset>
 */
final class FixedAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FixedAsset::class);
    }

    /** @return list<FixedAsset> by account, then code */
    public function findByDossier(Dossier $dossier): array
    {
        /* @var list<FixedAsset> */
        return $this->createQueryBuilder('f')
            ->join('f.account', 'a')
            ->addSelect('a')
            ->leftJoin('f.depreciationAccount', 'd')
            ->addSelect('d')
            ->andWhere('f.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('a.number', 'ASC')
            ->addOrderBy('f.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(Dossier $dossier, string $code): ?FixedAsset
    {
        /* @var FixedAsset|null */
        return $this->createQueryBuilder('f')
            ->andWhere('f.dossier = :dossier')
            ->andWhere('f.code = :code')
            ->setParameter('dossier', $dossier)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<string> the codes of the fixed assets kept on this account, or depreciated on it */
    public function codesOnAccount(Account $account): array
    {
        /* @var list<string> */
        return $this->createQueryBuilder('f')
            ->select('f.code')
            ->andWhere('f.account = :account OR f.depreciationAccount = :account')
            ->setParameter('account', $account)
            ->orderBy('f.code', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }
}
