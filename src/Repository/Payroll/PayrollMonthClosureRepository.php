<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollMonthClosure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * DossierOwned: read inside the dossier's context.
 *
 * @extends ServiceEntityRepository<PayrollMonthClosure>
 */
final class PayrollMonthClosureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayrollMonthClosure::class);
    }

    /** The last month of pay closed in the dossier, if any. */
    public function findLast(Dossier $dossier): ?PayrollMonthClosure
    {
        return $this->findOneBy(['dossier' => $dossier], ['month' => 'DESC']);
    }
}
