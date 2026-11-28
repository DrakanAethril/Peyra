<?php

declare(strict_types=1);

namespace App\Entity\Payroll;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Repository\Payroll\PayrollEstablishmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The establishment the payroll is made for - one per dossier; its SIRET and its APE code are the
 * fiche société's. It holds what the law leaves to each employer: the headcount the thresholds read
 * (11, 20, 50), the work accident rate the Carsat notified, the mobility payment rate of its
 * territory, the collective working time. Every rate is an exact decimal text in percent (« 2.1 »).
 * PayrollEstablishmentSheet is the one writer.
 */
#[ORM\Entity(repositoryClass: PayrollEstablishmentRepository::class)]
#[ORM\Table(name: 'payroll_establishment')]
#[ORM\UniqueConstraint(name: 'uniq_payroll_establishment_dossier', columns: ['dossier_id'])]
class PayrollEstablishment implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The average headcount (« effectif moyen annuel »), « 8.69 ». */
    #[ORM\Column(length: 12)]
    private string $headcount;

    /** The work accident rate notified for the year, in percent. */
    #[ORM\Column(length: 12)]
    private string $workAccidentRate;

    /** The mobility payment rate of the territory, in percent; null when none is due. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $mobilityRate;

    /** The collective monthly working time, in hours (« 151.67 »). */
    #[ORM\Column(length: 12)]
    private string $monthlyHours;

    public function __construct(Dossier $dossier, string $headcount, string $workAccidentRate, ?string $mobilityRate, string $monthlyHours)
    {
        $this->dossier = $dossier;
        $this->headcount = $headcount;
        $this->workAccidentRate = $workAccidentRate;
        $this->mobilityRate = $mobilityRate;
        $this->monthlyHours = $monthlyHours;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHeadcount(): string
    {
        return $this->headcount;
    }

    public function getWorkAccidentRate(): string
    {
        return $this->workAccidentRate;
    }

    public function getMobilityRate(): ?string
    {
        return $this->mobilityRate;
    }

    public function getMonthlyHours(): string
    {
        return $this->monthlyHours;
    }

    /** PayrollEstablishmentSheet is the caller. */
    public function describe(string $headcount, string $workAccidentRate, ?string $mobilityRate, string $monthlyHours): void
    {
        $this->headcount = $headcount;
        $this->workAccidentRate = $workAccidentRate;
        $this->mobilityRate = $mobilityRate;
        $this->monthlyHours = $monthlyHours;
    }
}
