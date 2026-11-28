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
 * entered on it - as PayslipElementsRules normalised them, see PayslipElements - what was changed by
 * hand on its lines (step 4.4b, PayslipAdjustments::toArray()) and where it stands. Until it is
 * validated its lines are not stored: the engine calculates them at every reading. Validated, the
 * payslip is frozen whole (`frozen`, PayslipSnapshot) on the dossier's date, and only that is read
 * again; sent to the employee (simulated), it keeps the day. One per employee and month; PayslipBook is
 * the one writer.
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

    /** @var array<string, mixed> what was changed by hand, PayslipAdjustments::toArray() */
    #[ORM\Column(type: Types::JSON)]
    private array $adjustments = [];

    /** @var array<string, mixed>|null the payslip as validated, PayslipSnapshot::toArray() */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $frozen = null;

    /** The dossier's date on the day it was validated. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedOn = null;

    /** The dossier's date on the day it was sent to the employee (simulated: nothing leaves). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentOn = null;

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

    /** @return array<string, mixed> */
    public function getAdjustments(): array
    {
        return $this->adjustments;
    }

    /** @return array<string, mixed>|null */
    public function getFrozen(): ?array
    {
        return $this->frozen;
    }

    public function getValidatedOn(): ?\DateTimeImmutable
    {
        return $this->validatedOn;
    }

    public function getSentOn(): ?\DateTimeImmutable
    {
        return $this->sentOn;
    }

    public function isValidated(): bool
    {
        return PayslipStatus::Validated === $this->status;
    }

    /**
     * @param array<string, mixed> $elements
     * @param array<string, mixed> $adjustments
     */
    public function calculated(array $elements, array $adjustments, bool $changedByHand): void
    {
        if ($this->isValidated()) {
            throw new \LogicException('A validated payslip is not calculated again.');
        }
        $this->elements = $elements;
        $this->adjustments = $adjustments;
        $this->status = $changedByHand ? PayslipStatus::Modified : PayslipStatus::Calculated;
    }

    /** @param array<string, mixed> $frozen */
    public function validate(array $frozen, \DateTimeImmutable $on): void
    {
        if ($this->isValidated()) {
            throw new \LogicException('This payslip is already validated.');
        }
        $this->frozen = $frozen;
        $this->validatedOn = $on;
        $this->status = PayslipStatus::Validated;
    }

    /** Back to its elements and adjustments, calculated again at the next reading; the audit trail keeps what was validated. */
    public function cancelValidation(bool $changedByHand): void
    {
        $this->frozen = null;
        $this->validatedOn = null;
        $this->sentOn = null;
        $this->status = $changedByHand ? PayslipStatus::Modified : PayslipStatus::Calculated;
    }

    public function send(\DateTimeImmutable $on): void
    {
        if (!$this->isValidated()) {
            throw new \LogicException('Only a validated payslip is sent.');
        }
        $this->sentOn ??= $on;
    }
}
