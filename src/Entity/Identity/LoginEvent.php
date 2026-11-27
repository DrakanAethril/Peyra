<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Entity\Shared\AppendOnly;
use App\Enum\Identity\LoginOutcome;
use App\Repository\Identity\LoginEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One sign-in attempt, successful or not, read by administrators (Administration › Journal des
 * connexions). Append-only. A refused person has no account: the line keeps the login typed and,
 * when the directory knew them, their name.
 */
#[ORM\Entity(repositoryClass: LoginEventRepository::class, readOnly: true)]
#[ORM\Index(name: 'idx_login_event_occurred', columns: ['occurred_at'])]
class LoginEvent implements AppendOnly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 190)]
    private string $login;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $displayName;

    #[ORM\Column(length: 40, enumType: LoginOutcome::class)]
    private LoginOutcome $outcome;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip;

    public function __construct(\DateTimeImmutable $occurredAt, string $login, LoginOutcome $outcome, ?User $user = null, ?string $displayName = null, ?string $ip = null)
    {
        $this->occurredAt = $occurredAt;
        $this->login = mb_substr($login, 0, 190);
        $this->outcome = $outcome;
        $this->user = $user;
        $this->displayName = $displayName;
        $this->ip = $ip;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function getOutcome(): LoginOutcome
    {
        return $this->outcome;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }
}
