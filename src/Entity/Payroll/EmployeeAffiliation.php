<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Payroll\EmployeeAffiliationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An employee's affiliation to one of the dossier's collecting bodies, and its day (step 4.2b):
 * the Urssaf's comes with the DPAE, the others are recorded by hand. HiringFormalities writes it.
 */
#[ORM\Entity(repositoryClass: EmployeeAffiliationRepository::class)]
#[ORM\Table(name: 'employee_affiliation')]
#[ORM\UniqueConstraint(name: 'uniq_employee_affiliation', columns: ['employee_id', 'body_id'])]
class EmployeeAffiliation implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Employee $employee;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PayrollBody $body;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $affiliatedOn;

    public function __construct(Employee $employee, PayrollBody $body, \DateTimeImmutable $affiliatedOn)
    {
        $this->dossier = $employee->getDossier();
        $this->employee = $employee;
        $this->body = $body;
        $this->affiliatedOn = $affiliatedOn;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): Employee
    {
        return $this->employee;
    }

    public function getBody(): PayrollBody
    {
        return $this->body;
    }

    public function getAffiliatedOn(): \DateTimeImmutable
    {
        return $this->affiliatedOn;
    }

    public function affiliateOn(\DateTimeImmutable $day): void
    {
        $this->affiliatedOn = $day;
    }
}
