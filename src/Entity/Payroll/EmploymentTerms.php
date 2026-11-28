<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Payroll\WorkingTime;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The terms of an employee's contract from a day on (step 4.2a): job, classification, status,
 * working time, monthly hours and base salary. The first terms start on the day of hire; each
 * change (« avenant », a raise, a new minimum wage) is a new row from its own day, and the rows are
 * the employee's history - the payslip engine reads the ones in force at the pay date. The hourly
 * rate is read, never stored: the base salary divided by the monthly hours.
 */
#[ORM\Entity]
#[ORM\Table(name: 'employment_terms')]
#[ORM\UniqueConstraint(name: 'uniq_employment_terms_day', columns: ['employee_id', 'effective_from'])]
class EmploymentTerms implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'terms')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Employee $employee;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(length: 80)]
    private string $jobTitle;

    /** The classification, free: no collective agreement is applied (lot 4). */
    #[ORM\Column(length: 120)]
    private string $classification;

    /** « Cadre » or not: the plan de paie's status conditions read it. */
    #[ORM\Column]
    private bool $executive;

    #[ORM\Column(length: 16, enumType: WorkingTime::class)]
    private WorkingTime $workingTime;

    /** The monthly hours of the contract, an exact decimal text (« 151.67 », « 104 »). */
    #[ORM\Column(length: 12)]
    private string $monthlyHours;

    #[ORM\Column(type: 'money')]
    private Money $baseSalary;

    public function __construct(Employee $employee, \DateTimeImmutable $effectiveFrom, string $jobTitle, string $classification, bool $executive, WorkingTime $workingTime, string $monthlyHours, Money $baseSalary)
    {
        $this->dossier = $employee->getDossier();
        $this->employee = $employee;
        $this->effectiveFrom = $effectiveFrom;
        $this->jobTitle = $jobTitle;
        $this->classification = $classification;
        $this->executive = $executive;
        $this->workingTime = $workingTime;
        $this->monthlyHours = $monthlyHours;
        $this->baseSalary = $baseSalary;
        $employee->addTerms($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function getJobTitle(): string
    {
        return $this->jobTitle;
    }

    public function getClassification(): string
    {
        return $this->classification;
    }

    public function isExecutive(): bool
    {
        return $this->executive;
    }

    public function getWorkingTime(): WorkingTime
    {
        return $this->workingTime;
    }

    public function getMonthlyHours(): string
    {
        return $this->monthlyHours;
    }

    public function getBaseSalary(): Money
    {
        return $this->baseSalary;
    }

    /** The hourly rate the fiche shows, at four decimals (« 15.8238 »): the base salary over the monthly hours. */
    public function getHourlyRate(): BigDecimal
    {
        return $this->baseSalary->toDecimal()->dividedBy($this->monthlyHours, 4, RoundingMode::HalfUp);
    }

    /** « Cadre » or « Non-cadre ». */
    public function getStatusLabel(): string
    {
        return $this->executive ? 'Cadre' : 'Non-cadre';
    }

    public function startOn(\DateTimeImmutable $day): void
    {
        $this->effectiveFrom = $day;
    }

    public function describe(string $jobTitle, string $classification, bool $executive, WorkingTime $workingTime, string $monthlyHours, Money $baseSalary): void
    {
        $this->jobTitle = $jobTitle;
        $this->classification = $classification;
        $this->executive = $executive;
        $this->workingTime = $workingTime;
        $this->monthlyHours = $monthlyHours;
        $this->baseSalary = $baseSalary;
    }
}
