<?php

declare(strict_types=1);

namespace App\Entity\Mission;

use App\Entity\Dossier\Distribution;
use App\Entity\Identity\User;
use App\Repository\Mission\MissionAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mission given to a distribution of its template (step 2.9a; design-ux.md § 4.2, « Donner une
 * mission »): from then on its settings are those of the distribution's dossiers - the latest mission
 * given wins, a mission « s'enchaîne sur la précédente » -, until the date it is due back (real
 * time, like the moment it was given).
 */
#[ORM\Entity(repositoryClass: MissionAssignmentRepository::class)]
#[ORM\Table(name: 'mission_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_mission_assignment', columns: ['mission_id', 'distribution_id'])]
class MissionAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Mission $mission;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Distribution $distribution;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $givenBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $givenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $dueAt;

    public function __construct(Mission $mission, Distribution $distribution, User $givenBy, \DateTimeImmutable $givenAt, \DateTimeImmutable $dueAt)
    {
        $this->mission = $mission;
        $this->distribution = $distribution;
        $this->givenBy = $givenBy;
        $this->givenAt = $givenAt;
        $this->dueAt = $dueAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMission(): Mission
    {
        return $this->mission;
    }

    public function getDistribution(): Distribution
    {
        return $this->distribution;
    }

    public function getGivenBy(): User
    {
        return $this->givenBy;
    }

    public function getGivenAt(): \DateTimeImmutable
    {
        return $this->givenAt;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }
}
