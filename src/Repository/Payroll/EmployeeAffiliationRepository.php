<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\EmployeeAffiliation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * DossierOwned: read inside the dossier's context.
 *
 * @extends ServiceEntityRepository<EmployeeAffiliation>
 */
final class EmployeeAffiliationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmployeeAffiliation::class);
    }

    /** @return list<EmployeeAffiliation> */
    public function findOfEmployee(Employee $employee): array
    {
        /** @var list<EmployeeAffiliation> $found */
        $found = $this->findBy(['employee' => $employee]);

        return $found;
    }

    /** @return array<int, list<EmployeeAffiliation>> by employee id */
    public function findByEmployee(Dossier $dossier): array
    {
        $found = [];
        foreach ($this->findBy(['dossier' => $dossier]) as $affiliation) {
            $found[(int) $affiliation->getEmployee()->getId()][] = $affiliation;
        }

        return $found;
    }
}
