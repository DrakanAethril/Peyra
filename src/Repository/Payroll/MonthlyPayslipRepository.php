<?php

declare(strict_types=1);

namespace App\Repository\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Payroll\Employee;
use App\Entity\Payroll\MonthlyPayslip;
use App\Enum\Payroll\PayslipStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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

    /** @return list<MonthlyPayslip> the employee's validated payslips from that day (included) to that month (excluded) */
    public function findValidatedBetween(Employee $employee, \DateTimeImmutable $from, \DateTimeImmutable $before): array
    {
        /** @var list<MonthlyPayslip> */
        return $this->createQueryBuilder('p')
            ->where('p.employee = :employee')->andWhere('p.status = :validated')
            ->andWhere('p.month >= :from')->andWhere('p.month < :before')
            ->setParameter('employee', $employee)->setParameter('validated', PayslipStatus::Validated)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)->setParameter('before', $before, Types::DATE_IMMUTABLE)
            ->orderBy('p.month')
            ->getQuery()->getResult();
    }
}
