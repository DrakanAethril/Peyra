<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Repository\Identity\AuthorizedTeacherRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of « Enseignants de la filière » (design-ux.md § 3.6): a teacher outside the CG groups
 * an administrator let in by name. Matched at sign-in on the directory's stable id, so a renamed
 * login stays authorised. Removing it is a date, not a deletion: the line stays as history, and
 * adding the teacher again writes a new one.
 */
#[ORM\Entity(repositoryClass: AuthorizedTeacherRepository::class)]
#[ORM\Index(name: 'idx_authorized_teacher_directory', columns: ['directory_id'])]
class AuthorizedTeacher
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $directoryId;

    /** The login and the name the directory gave when the teacher was added. */
    #[ORM\Column(length: 190)]
    private string $login;

    #[ORM\Column(length: 190)]
    private string $displayName;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $addedBy;

    #[ORM\Column]
    private \DateTimeImmutable $addedAt;

    #[ORM\ManyToOne]
    private ?User $revokedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(string $directoryId, string $login, string $displayName, User $addedBy, \DateTimeImmutable $addedAt)
    {
        $this->directoryId = $directoryId;
        $this->login = $login;
        $this->displayName = $displayName;
        $this->addedBy = $addedBy;
        $this->addedAt = $addedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDirectoryId(): string
    {
        return $this->directoryId;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getAddedBy(): User
    {
        return $this->addedBy;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }

    public function revoke(User $by, \DateTimeImmutable $at): void
    {
        if (null !== $this->revokedAt) {
            return;
        }
        $this->revokedBy = $by;
        $this->revokedAt = $at;
    }

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
