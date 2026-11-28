<?php

declare(strict_types=1);

namespace App\Repository\Banking;

use App\Entity\Banking\ChequeDeposit;
use App\Entity\Dossier\Dossier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The remittances of cheques are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<ChequeDeposit>
 */
final class ChequeDepositRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChequeDeposit::class);
    }

    /** @return list<ChequeDeposit> the latest first */
    public function findLatestFirst(Dossier $dossier): array
    {
        return $this->findBy(['dossier' => $dossier], ['depositedOn' => 'DESC', 'number' => 'DESC']);
    }

    public function lastNumber(Dossier $dossier): int
    {
        $last = $this->findOneBy(['dossier' => $dossier], ['number' => 'DESC']);

        return null === $last ? 0 : $last->getNumber();
    }
}
