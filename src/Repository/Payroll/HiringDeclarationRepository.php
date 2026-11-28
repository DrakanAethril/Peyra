<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\HiringDeclaration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * DossierOwned: read inside the dossier's context.
 *
 * @extends ServiceEntityRepository<HiringDeclaration>
 */
final class HiringDeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HiringDeclaration::class);
    }

    public function findOfEmployee(Employee $employee): ?HiringDeclaration
    {
        return $this->findOneBy(['employee' => $employee]);
    }

    /** @return array<int, HiringDeclaration> by employee id */
    public function findByEmployee(Dossier $dossier): array
    {
        $found = [];
        foreach ($this->findBy(['dossier' => $dossier]) as $declaration) {
            $found[(int) $declaration->getEmployee()->getId()] = $declaration;
        }

        return $found;
    }

    public function countOfDossier(Dossier $dossier): int
    {
        return $this->count(['dossier' => $dossier]);
    }
}
