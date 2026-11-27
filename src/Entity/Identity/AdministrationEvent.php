<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Enum\Identity\AdministrationAction;
use App\Repository\Identity\AdministrationEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One gesture of the administration screens (design-ux.md § 3.6: « Tout geste de ces deux écrans
 * est écrit au journal d'administration »). Append-only; the subject's login and name are copied,
 * so the line reads the same after a rename.
 */
#[ORM\Entity(repositoryClass: AdministrationEventRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_administration_event_occurred', columns: ['occurred_at'])]
class AdministrationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $actor;

    #[ORM\Column(length: 40, enumType: AdministrationAction::class)]
    private AdministrationAction $action;

    #[ORM\Column(length: 190)]
    private string $subjectLogin;

    #[ORM\Column(length: 190)]
    private string $subjectName;

    public function __construct(\DateTimeImmutable $occurredAt, User $actor, AdministrationAction $action, string $subjectLogin, string $subjectName)
    {
        $this->occurredAt = $occurredAt;
        $this->actor = $actor;
        $this->action = $action;
        $this->subjectLogin = $subjectLogin;
        $this->subjectName = $subjectName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function getAction(): AdministrationAction
    {
        return $this->action;
    }

    public function getSubjectLogin(): string
    {
        return $this->subjectLogin;
    }

    public function getSubjectName(): string
    {
        return $this->subjectName;
    }
}
