<?php

declare(strict_types=1);

namespace App\Entity\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\RestorePoint;
use App\Entity\Identity\User;
use App\Repository\Mission\MissionProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a dossier stands on the mission it plays (step 2.9b; design-ux.md § 2.3): the tasks its
 * student declared done (« Tâche terminée », by task key) and, once handed in, when, by whom and the
 * « Rendu » restore point that froze it - what the correction reads. Global: a return to a restore
 * point never takes back what was declared or handed in. Written by StudentMissions alone.
 */
#[ORM\Entity(repositoryClass: MissionProgressRepository::class)]
#[ORM\Table(name: 'mission_progress')]
#[ORM\UniqueConstraint(name: 'uniq_mission_progress', columns: ['assignment_id', 'dossier_id'])]
class MissionProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MissionAssignment $assignment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    /** @var list<string> keys of the tasks declared done */
    #[ORM\Column(type: Types::JSON)]
    private array $declaredTasks = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    /** The « Rendu » point; null once a teacher deleted it (the hand-in stays). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?RestorePoint $restorePoint = null;

    public function __construct(MissionAssignment $assignment, Dossier $dossier)
    {
        $this->assignment = $assignment;
        $this->dossier = $dossier;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): MissionAssignment
    {
        return $this->assignment;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    /** @return list<string> */
    public function getDeclaredTasks(): array
    {
        return $this->declaredTasks;
    }

    public function hasDeclared(string $key): bool
    {
        return \in_array($key, $this->declaredTasks, true);
    }

    public function declare(string $key, bool $done): void
    {
        $keys = array_values(array_diff($this->declaredTasks, [$key]));
        if ($done) {
            $keys[] = $key;
        }
        $this->declaredTasks = $keys;
    }

    public function isSubmitted(): bool
    {
        return null !== $this->submittedAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function getRestorePoint(): ?RestorePoint
    {
        return $this->restorePoint;
    }

    public function markSubmitted(User $by, RestorePoint $point, \DateTimeImmutable $at): void
    {
        if ($this->isSubmitted()) {
            throw new \LogicException('A mission is handed in once.');
        }
        $this->submittedAt = $at;
        $this->submittedBy = $by;
        $this->restorePoint = $point;
    }
}
