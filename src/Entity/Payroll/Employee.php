<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Enum\Payroll\ContractType;
use App\Enum\Payroll\EmployeeState;
use App\Enum\Payroll\Sex;
use App\Repository\Payroll\EmployeeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An employee of the dossier's company (lot 4, step 4.2a) - fictitious like the company: their
 * civil status, their contract (its type, the day it starts, the end a fixed-term contract or an
 * apprenticeship names) and their withholding rate. What changes along the contract - the job, the
 * classification, the status, the working time, the base salary - is dated, one EmploymentTerms
 * per change: the first starts on the day of hire, and their sequence is the employee's history.
 * EmployeeSheet is the one writer.
 */
#[ORM\Entity(repositoryClass: EmployeeRepository::class)]
#[ORM\Table(name: 'employee')]
#[ORM\UniqueConstraint(name: 'uniq_employee_number', columns: ['dossier_id', 'registration_number'])]
class Employee implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The matricule, « S001 »: its address in the dossier. */
    #[ORM\Column(length: 10)]
    private string $registrationNumber;

    #[ORM\Column(length: 80)]
    private string $lastName;

    #[ORM\Column(length: 80)]
    private string $firstName;

    #[ORM\Column(length: 1, enumType: Sex::class)]
    private Sex $sex;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $birthDate;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $birthPlace = null;

    #[ORM\Column(length: 40)]
    private string $nationality;

    /** The social security number, 15 characters without spaces; fictitious, its key never valid. */
    #[ORM\Column(length: 15, nullable: true)]
    private ?string $nir = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $street = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $city = null;

    /** The withholding rate the tax office sent, in percent (« 3.5 »); null: none sent, the neutral rate applies. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $withholdingRate = null;

    #[ORM\Column(length: 20, enumType: ContractType::class)]
    private ContractType $contractType;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $hiredOn;

    /** The end a fixed-term contract or an apprenticeship names. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contractEndsOn = null;

    /** A fixed-term contract's ground (« accroissement temporaire d'activité »). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $fixedTermReason = null;

    /** The diploma an apprentice prepares. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $diploma = null;

    /** The apprentice's training centre (CFA). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $trainingCentre = null;

    /** @var Collection<int, EmploymentTerms> */
    #[ORM\OneToMany(targetEntity: EmploymentTerms::class, mappedBy: 'employee', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['effectiveFrom' => 'ASC'])]
    private Collection $terms;

    public function __construct(Dossier $dossier, string $registrationNumber, string $lastName, string $firstName, Sex $sex, \DateTimeImmutable $birthDate, ContractType $contractType, \DateTimeImmutable $hiredOn)
    {
        $this->dossier = $dossier;
        $this->registrationNumber = $registrationNumber;
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->sex = $sex;
        $this->birthDate = $birthDate;
        $this->nationality = 'Française';
        $this->contractType = $contractType;
        $this->hiredOn = $hiredOn;
        $this->terms = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRegistrationNumber(): string
    {
        return $this->registrationNumber;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /** « Thomas Lefèvre ». */
    public function getFullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    /** « Lefèvre Thomas »: how lists of people show and sort. */
    public function getSortName(): string
    {
        return $this->lastName.' '.$this->firstName;
    }

    public function getSex(): Sex
    {
        return $this->sex;
    }

    public function getBirthDate(): \DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function getBirthPlace(): ?string
    {
        return $this->birthPlace;
    }

    public function getNationality(): string
    {
        return $this->nationality;
    }

    public function getNir(): ?string
    {
        return $this->nir;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function getWithholdingRate(): ?string
    {
        return $this->withholdingRate;
    }

    public function getContractType(): ContractType
    {
        return $this->contractType;
    }

    public function getHiredOn(): \DateTimeImmutable
    {
        return $this->hiredOn;
    }

    public function getContractEndsOn(): ?\DateTimeImmutable
    {
        return $this->contractEndsOn;
    }

    public function getFixedTermReason(): ?string
    {
        return $this->fixedTermReason;
    }

    public function getDiploma(): ?string
    {
        return $this->diploma;
    }

    public function getTrainingCentre(): ?string
    {
        return $this->trainingCentre;
    }

    public function identify(string $registrationNumber, string $lastName, string $firstName, Sex $sex, \DateTimeImmutable $birthDate, ?string $birthPlace, string $nationality, ?string $nir): void
    {
        $this->registrationNumber = $registrationNumber;
        $this->lastName = $lastName;
        $this->firstName = $firstName;
        $this->sex = $sex;
        $this->birthDate = $birthDate;
        $this->birthPlace = $birthPlace;
        $this->nationality = $nationality;
        $this->nir = $nir;
    }

    public function locate(?string $street, ?string $postalCode, ?string $city): void
    {
        $this->street = $street;
        $this->postalCode = $postalCode;
        $this->city = $city;
    }

    public function withhold(?string $rate): void
    {
        $this->withholdingRate = $rate;
    }

    public function contract(ContractType $type, \DateTimeImmutable $hiredOn, ?\DateTimeImmutable $endsOn, ?string $fixedTermReason, ?string $diploma, ?string $trainingCentre): void
    {
        $this->contractType = $type;
        $this->hiredOn = $hiredOn;
        $this->contractEndsOn = $endsOn;
        $this->fixedTermReason = $fixedTermReason;
        $this->diploma = $diploma;
        $this->trainingCentre = $trainingCentre;
    }

    /** @return list<EmploymentTerms> from the first, on the day of hire, to the latest */
    public function getTerms(): array
    {
        $terms = $this->terms->toArray();
        usort($terms, static fn (EmploymentTerms $a, EmploymentTerms $b): int => $a->getEffectiveFrom() <=> $b->getEffectiveFrom());

        return array_values($terms);
    }

    public function addTerms(EmploymentTerms $terms): void
    {
        $this->terms->add($terms);
    }

    public function removeTerms(EmploymentTerms $terms): void
    {
        $this->terms->removeElement($terms);
    }

    /** The terms starting that very day, if any. */
    public function termsStartingOn(\DateTimeImmutable $day): ?EmploymentTerms
    {
        foreach ($this->terms as $terms) {
            if ($terms->getEffectiveFrom()->format('Y-m-d') === $day->format('Y-m-d')) {
                return $terms;
            }
        }

        return null;
    }

    /** The terms in force that day: the latest that started on or before it, else the first (a hire still to come). */
    public function termsAt(\DateTimeImmutable $day): EmploymentTerms
    {
        $all = $this->getTerms();
        $found = $all[0] ?? throw new \LogicException(\sprintf('Employee %s has no terms.', $this->registrationNumber));
        foreach ($all as $terms) {
            if ($terms->getEffectiveFrom() <= $day) {
                $found = $terms;
            }
        }

        return $found;
    }

    public function stateAt(\DateTimeImmutable $day): EmployeeState
    {
        return $this->hiredOn > $day ? EmployeeState::Upcoming : EmployeeState::Present;
    }
}
