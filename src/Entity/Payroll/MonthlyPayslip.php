<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Payroll\PayslipStatus;
use App\Repository\Payroll\MonthlyPayslipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An employee's payslip of one month (step 4.4a), à la manière de Sage: the elements of the month
 * entered on it - as PayslipElementsRules normalised them, see PayslipElements - and where it stands.
 * The lines are not stored: the engine calculates them from the elements, the fiche and the plan at
 * every reading. One per employee and month; PayslipBook is the one writer.
 */
#[ORM\Entity(repositoryClass: MonthlyPayslipRepository::class)]
#[ORM\Table(name: 'monthly_payslip')]
#[ORM\UniqueConstraint(name: 'uniq_monthly_payslip_employee_month', columns: ['employee_id', 'month'])]
class MonthlyPayslip implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Employee $employee;

    /** The first day of the month paid. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $month;

    #[ORM\Column(length: 16, enumType: PayslipStatus::class)]
    private PayslipStatus $status = PayslipStatus::ToDo;

    /** @var array<string, mixed> the elements of the month, PayslipElements::toArray() */
    #[ORM\Column(type: Types::JSON)]
    private array $elements = [];

    public function __construct(Employee $employee, \DateTimeImmutable $month)
    {
        $this->dossier = $employee->getDossier();
        $this->employee = $employee;
        $this->month = $month;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getMonth(): \DateTimeImmutable
    {
        return $this->month;
    }

    public function getStatus(): PayslipStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getElements(): array
    {
        return $this->elements;
    }

    /** @param array<string, mixed> $elements */
    public function calculated(array $elements): void
    {
        $this->elements = $elements;
        $this->status = PayslipStatus::Calculated;
    }
}
