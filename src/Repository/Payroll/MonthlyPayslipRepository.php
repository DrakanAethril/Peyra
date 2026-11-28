<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * DossierOwned: read inside the dossier's context.
 *
 * @extends ServiceEntityRepository<MonthlyPayslip>
 */
final class MonthlyPayslipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonthlyPayslip::class);
    }

    public function findOne(Employee $employee, \DateTimeImmutable $month): ?MonthlyPayslip
    {
        return $this->findOneBy(['employee' => $employee, 'month' => $month]);
    }

    /** @return array<int, MonthlyPayslip> the payslips of that month, by employee id */
    public function findOfMonth(Dossier $dossier, \DateTimeImmutable $month): array
    {
        $found = [];
        foreach ($this->findBy(['dossier' => $dossier, 'month' => $month]) as $payslip) {
            $found[(int) $payslip->getEmployee()->getId()] = $payslip;
        }

        return $found;
    }
}
