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
        /* @var list<MonthlyPayslip> */
        return $this->createQueryBuilder('p')
            ->where('p.employee = :employee')->andWhere('p.status = :validated')
            ->andWhere('p.month >= :from')->andWhere('p.month < :before')
            ->setParameter('employee', $employee)->setParameter('validated', PayslipStatus::Validated)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)->setParameter('before', $before, Types::DATE_IMMUTABLE)
            ->orderBy('p.month')
            ->getQuery()->getResult();
    }

    /** @return list<MonthlyPayslip> every payslip of the employee, by month */
    public function findOfEmployee(Employee $employee): array
    {
        return $this->findBy(['employee' => $employee], ['month' => 'ASC']);
    }

    /** @return list<\DateTimeImmutable> the months of the employee's validated payslips, in order */
    public function findValidatedMonths(Employee $employee): array
    {
        $months = [];
        foreach ($this->findBy(['employee' => $employee, 'status' => PayslipStatus::Validated], ['month' => 'ASC']) as $payslip) {
            $months[] = $payslip->getMonth();
        }

        return $months;
    }

    /** @return list<MonthlyPayslip> the validated payslips of that month, their employees loaded, by the employees' names */
    public function findValidatedOfMonth(Dossier $dossier, \DateTimeImmutable $month): array
    {
        /* @var list<MonthlyPayslip> */
        return $this->createQueryBuilder('p')
            ->addSelect('e')->join('p.employee', 'e')
            ->where('p.dossier = :dossier')->andWhere('p.month = :month')->andWhere('p.status = :validated')
            ->setParameter('dossier', $dossier)->setParameter('month', $month, Types::DATE_IMMUTABLE)->setParameter('validated', PayslipStatus::Validated)
            ->orderBy('e.lastName')->addOrderBy('e.firstName')->addOrderBy('e.registrationNumber')
            ->getQuery()->getResult();
    }

    /** @return list<\DateTimeImmutable> the months that have a validated payslip, the latest first */
    public function findValidatedMonthsOf(Dossier $dossier): array
    {
        $months = [];
        foreach ($this->createQueryBuilder('p')->select('DISTINCT p.month AS month')
            ->where('p.dossier = :dossier')->andWhere('p.status = :validated')
            ->setParameter('dossier', $dossier)->setParameter('validated', PayslipStatus::Validated)
            ->orderBy('p.month', 'DESC')->getQuery()->getArrayResult() as $row) {
            $month = \is_array($row) ? ($row['month'] ?? null) : null;
            if ($month instanceof \DateTimeImmutable) {
                $months[] = $month;
            }
        }

        return $months;
    }
}
