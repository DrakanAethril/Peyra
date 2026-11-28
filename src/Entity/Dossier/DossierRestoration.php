<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\User;
use App\Enum\Dossier\RestorationState;
use App\Repository\Dossier\DossierRestorationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A return of a dossier to one of its restore points (design-ux.md § 4.9, step 2): while it runs,
 * every screen of the dossier answers its waiting page; done, the dossier holds the point's
 * content; failed, it holds what it held before, and the failure is said on the points' list and
 * on the home of its teachers. The point's name is kept: the point may be deleted later, the
 * history of the return stays. Global, like the points themselves.
 */
#[ORM\Entity(repositoryClass: DossierRestorationRepository::class)]
#[ORM\Table(name: 'dossier_restoration')]
#[ORM\Index(name: 'idx_dossier_restoration_state', columns: ['state'])]
class DossierRestoration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?RestorePoint $point;

    #[ORM\Column(length: RestorePoint::NAME_MAX)]
    private string $pointName;

    /** The point of the state the dossier was in, posed first when asked (« Poser d'abord un point de l'état actuel »). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?RestorePoint $safetyPoint;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $requestedBy;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 16, enumType: RestorationState::class)]
    private RestorationState $state = RestorationState::Running;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failure = null;

    public function __construct(RestorePoint $point, ?RestorePoint $safetyPoint, ?User $requestedBy, \DateTimeImmutable $requestedAt)
    {
        if (!$point->isReady() || ($safetyPoint && $safetyPoint->getDossier() !== $point->getDossier())) {
            throw new \LogicException('A dossier goes back to one of its own ready points.');
        }
        $this->dossier = $point->getDossier();
        $this->point = $point;
        $this->pointName = $point->getName();
        $this->safetyPoint = $safetyPoint;
        $this->requestedBy = $requestedBy;
        $this->requestedAt = $requestedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getPoint(): ?RestorePoint
    {
        return $this->point;
    }

    public function getPointName(): string
    {
        return $this->pointName;
    }

    public function getSafetyPoint(): ?RestorePoint
    {
        return $this->safetyPoint;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getState(): RestorationState
    {
        return $this->state;
    }

    public function isRunning(): bool
    {
        return RestorationState::Running === $this->state;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function markDone(\DateTimeImmutable $at): void
    {
        $this->expectRunning();
        $this->state = RestorationState::Done;
        $this->finishedAt = $at;
    }

    public function markFailed(string $reason, \DateTimeImmutable $at): void
    {
        $this->expectRunning();
        $this->state = RestorationState::Failed;
        $this->failure = mb_substr($reason, 0, 255);
        $this->finishedAt = $at;
    }

    private function expectRunning(): void
    {
        if (!$this->isRunning()) {
            throw new \LogicException('Only a running return ends.');
        }
    }
}
