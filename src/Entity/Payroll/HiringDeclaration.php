<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Payroll\HiringDeclarationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A DPAE the simulated social portal accepted (step 4.2b): its acknowledgement number, the
 * dossier's date it was deposited on, whether it came after the hire, and what it declared - frozen,
 * so the acknowledgement keeps saying it after the fiche changes. One per employee; a rejected
 * deposit leaves nothing. HiringFormalities::declare() is the one writer.
 */
#[ORM\Entity(repositoryClass: HiringDeclarationRepository::class)]
#[ORM\Table(name: 'hiring_declaration')]
#[ORM\UniqueConstraint(name: 'uniq_hiring_declaration_employee', columns: ['employee_id'])]
#[ORM\UniqueConstraint(name: 'uniq_hiring_declaration_number', columns: ['dossier_id', 'number'])]
class HiringDeclaration implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Employee $employee;

    /** The acknowledgement's number, « DPAE-2026-000001 ». */
    #[ORM\Column(length: 20)]
    private string $number;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $declaredOn;

    /** Deposited after the day of hire. */
    #[ORM\Column]
    private bool $late;

    /** @var list<array{0: string, 1: string}> what was declared, label and value, in the portal's order */
    #[ORM\Column(type: Types::JSON)]
    private array $content;

    /** @param list<array{0: string, 1: string}> $content */
    public function __construct(Employee $employee, string $number, \DateTimeImmutable $declaredOn, bool $late, array $content)
    {
        $this->dossier = $employee->getDossier();
        $this->employee = $employee;
        $this->number = $number;
        $this->declaredOn = $declaredOn;
        $this->late = $late;
        $this->content = $content;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getDeclaredOn(): \DateTimeImmutable
    {
        return $this->declaredOn;
    }

    public function isLate(): bool
    {
        return $this->late;
    }

    /** @return list<array{0: string, 1: string}> */
    public function getContent(): array
    {
        return $this->content;
    }
}
