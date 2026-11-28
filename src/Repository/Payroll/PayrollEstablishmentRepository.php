<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\PayrollEstablishment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The establishment of a dossier's payroll: one at most, read inside the dossier's context.
 *
 * @extends ServiceEntityRepository<PayrollEstablishment>
 */
final class PayrollEstablishmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PayrollEstablishment::class);
    }

    public function findOfDossier(Dossier $dossier): ?PayrollEstablishment
    {
        return $this->findOneBy(['dossier' => $dossier]);
    }
}
