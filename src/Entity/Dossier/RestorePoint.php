<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Enum\Dossier\RestorePointOrigin;
use App\Enum\Dossier\RestorePointState;
use App\Repository\Dossier\RestorePointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A restore point of a dossier (conception § 4, design-ux.md § 4.9): a name, who posed it, when in
 * real time and at which date of the dossier, and the frozen copy it keeps - a Dossier of kind
 * Snapshot, filled by DossierCopier in the background and never opened as a dossier. Global, like
 * the dossier's members: it says what the dossier can go back to, it is not its content, and no
 * copy carries it (App\Service\Dossier\Restore\RestorePoints is the one door).
 */
#[ORM\Entity(repositoryClass: RestorePointRepository::class)]
#[ORM\Table(name: 'restore_point')]
#[ORM\Index(name: 'idx_restore_point_state', columns: ['state'])]
class RestorePoint
{
    public const int NAME_MAX = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    /** The frozen copy: deleted with the point, by DossierEraser. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Dossier $snapshot;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    #[ORM\Column(length: 16, enumType: RestorePointOrigin::class)]
    private RestorePointOrigin $origin;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author;

    /** Real time: when the point was asked for (the audit trail's clock, shown on the points' list). */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** The dossier's date when the point was posed. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dossierDate;

    #[ORM\Column(length: 16, enumType: RestorePointState::class)]
    private RestorePointState $state = RestorePointState::Preparing;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Why the copy stopped, in French, when it did. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failure = null;

    public function __construct(Dossier $dossier, Dossier $snapshot, string $name, RestorePointOrigin $origin, ?User $author, \DateTimeImmutable $createdAt)
    {
        if (DossierKind::Snapshot === $dossier->getKind() || DossierKind::Snapshot !== $snapshot->getKind()) {
            throw new \LogicException('A restore point keeps a snapshot of a dossier that is not one.');
        }
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new \LogicException('A restore point has a name of 1 to 80 characters.');
        }
        $this->dossier = $dossier;
        $this->snapshot = $snapshot;
        $this->name = $name;
        $this->origin = $origin;
        $this->author = $author;
        $this->createdAt = $createdAt;
        $this->dossierDate = $dossier->getDossierDate();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getSnapshot(): Dossier
    {
        return $this->snapshot;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrigin(): RestorePointOrigin
    {
        return $this->origin;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDossierDate(): \DateTimeImmutable
    {
        return $this->dossierDate;
    }

    public function getState(): RestorePointState
    {
        return $this->state;
    }

    public function isReady(): bool
    {
        return RestorePointState::Ready === $this->state;
    }

    public function isPreparing(): bool
    {
        return RestorePointState::Preparing === $this->state;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function markReady(\DateTimeImmutable $at): void
    {
        $this->expectPreparing();
        $this->state = RestorePointState::Ready;
        $this->finishedAt = $at;
    }

    public function markFailed(string $reason, \DateTimeImmutable $at): void
    {
        $this->expectPreparing();
        $this->state = RestorePointState::Failed;
        $this->failure = mb_substr($reason, 0, 255);
        $this->finishedAt = $at;
    }

    private function expectPreparing(): void
    {
        if (!$this->isPreparing()) {
            throw new \LogicException('Only a point in preparation becomes ready or fails.');
        }
    }
}
