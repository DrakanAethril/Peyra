<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Payroll\HeadcountCondition;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollCeiling;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollStatusCondition;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\SalaryCondition;
use App\Repository\Payroll\PayrollItemRepository;
use App\Service\Payroll\Plan\PayrollItemSpec;
use Doctrine\ORM\Mapping as ORM;

/**
 * An item (« rubrique ») of the dossier's plan de paie, à la manière de Sage (lot 4, step 4.1): its
 * code, its family, its place in the payslip, its base and ceiling, the rate of each side - named,
 * never written when the law fixes it (PayrollRate) -, the conditions it applies under (status,
 * headcount, salary bracket), and for a contribution the body it is paid to and the line of the
 * clarified payslip it is summed under. PayrollItemSheet is the one writer by hand,
 * PayrollPlanInstaller the one that installs the standard plan.
 */
#[ORM\Entity(repositoryClass: PayrollItemRepository::class)]
#[ORM\Table(name: 'payroll_item')]
#[ORM\UniqueConstraint(name: 'uniq_payroll_item_dossier_code', columns: ['dossier_id', 'code'])]
class PayrollItem implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Four digits, « 2200 ». */
    #[ORM\Column(length: 4)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $label;

    #[ORM\Column(length: 16, enumType: PayrollItemKind::class)]
    private PayrollItemKind $kind;

    /** Its place in the payslip, inside its family. */
    #[ORM\Column]
    private int $sortOrder;

    /** It comes off what it adds to: an absence off the gross pay, a reduction off the contributions. */
    #[ORM\Column]
    private bool $subtracts;

    /** An element of gross pay received in kind (a company car, meals): in the gross pay, then off the net. */
    #[ORM\Column]
    private bool $benefitInKind;

    #[ORM\Column(length: 20, enumType: PayrollBase::class)]
    private PayrollBase $base;

    #[ORM\Column(length: 16, enumType: PayrollCeiling::class)]
    private PayrollCeiling $ceiling;

    #[ORM\Embedded(class: PayrollRate::class, columnPrefix: 'employee_')]
    private PayrollRate $employeeRate;

    #[ORM\Embedded(class: PayrollRate::class, columnPrefix: 'employer_')]
    private PayrollRate $employerRate;

    #[ORM\Column(length: 16, enumType: PayrollStatusCondition::class)]
    private PayrollStatusCondition $statusCondition;

    #[ORM\Column(length: 16, enumType: HeadcountCondition::class)]
    private HeadcountCondition $headcountCondition;

    #[ORM\Column(length: 16, enumType: SalaryCondition::class)]
    private SalaryCondition $salaryCondition;

    /** The body a contribution is paid to; a body a contribution names is not deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?PayrollBody $body = null;

    #[ORM\Column(length: 32, nullable: true, enumType: PayslipLine::class)]
    private ?PayslipLine $payslipLine = null;

    public function __construct(Dossier $dossier, PayrollItemSpec $spec)
    {
        $this->dossier = $dossier;
        $this->describe($spec);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getKind(): PayrollItemKind
    {
        return $this->kind;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function subtracts(): bool
    {
        return $this->subtracts;
    }

    public function isBenefitInKind(): bool
    {
        return $this->benefitInKind;
    }

    public function getBase(): PayrollBase
    {
        return $this->base;
    }

    public function getCeiling(): PayrollCeiling
    {
        return $this->ceiling;
    }

    public function getEmployeeRate(): PayrollRate
    {
        return $this->employeeRate;
    }

    public function getEmployerRate(): PayrollRate
    {
        return $this->employerRate;
    }

    public function getStatusCondition(): PayrollStatusCondition
    {
        return $this->statusCondition;
    }

    public function getHeadcountCondition(): HeadcountCondition
    {
        return $this->headcountCondition;
    }

    public function getSalaryCondition(): SalaryCondition
    {
        return $this->salaryCondition;
    }

    public function getBody(): ?PayrollBody
    {
        return $this->body;
    }

    public function getPayslipLine(): ?PayslipLine
    {
        return $this->payslipLine;
    }

    /** PayrollItemSheet and PayrollPlanInstaller are the callers: the spec has passed PayrollItemRules. */
    public function describe(PayrollItemSpec $spec): void
    {
        $this->code = $spec->code;
        $this->label = $spec->label;
        $this->kind = $spec->kind;
        $this->sortOrder = $spec->sortOrder;
        $this->subtracts = $spec->subtracts;
        $this->benefitInKind = $spec->benefitInKind;
        $this->base = $spec->base;
        $this->ceiling = $spec->ceiling;
        $this->employeeRate = $spec->employeeRate;
        $this->employerRate = $spec->employerRate;
        $this->statusCondition = $spec->status;
        $this->headcountCondition = $spec->headcount;
        $this->salaryCondition = $spec->salary;
        $this->body = $spec->body;
        $this->payslipLine = $spec->line;
    }
}
