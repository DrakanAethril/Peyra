<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The employees are DossierOwned: every method reads inside the dossier's context.
 *
 * @extends ServiceEntityRepository<Employee>
 */
final class EmployeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employee::class);
    }

    /** @return list<Employee> by matricule, their terms loaded */
    public function findByDossier(Dossier $dossier): array
    {
        /** @var list<Employee> $employees */
        $employees = $this->createQueryBuilder('e')
            ->addSelect('t')
            ->leftJoin('e.terms', 't')
            ->andWhere('e.dossier = :dossier')
            ->setParameter('dossier', $dossier)
            ->orderBy('e.registrationNumber', 'ASC')
            ->addOrderBy('t.effectiveFrom', 'ASC')
            ->getQuery()
            ->getResult();

        return $employees;
    }

    public function findOneByNumber(Dossier $dossier, string $registrationNumber): ?Employee
    {
        return $this->findOneBy(['dossier' => $dossier, 'registrationNumber' => $registrationNumber]);
    }
}
