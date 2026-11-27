<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Repository\Identity\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A person of the directory let in by the entry rule, mirrored locally so that dossiers, missions
 * and journals attach to a stable row (CLAUDE.md, « Authentication and access »).
 *
 * The account is keyed on the directory's stable id, never on the login: a login renamed in the
 * directory finds the same row, which then takes the new login. No password is ever stored. The
 * name, the address, the groups and the kind are written again at each sign-in; the administrator
 * flag is Peyra's own.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'user_account')]
#[ORM\Index(name: 'idx_user_account_login', columns: ['login'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `<attribute>:<value>`, e.g. `entryUUID:4960fd9e-…` or `uidNumber:40104` (StableId). */
    #[ORM\Column(length: 190, unique: true)]
    private string $directoryId;

    #[ORM\Column(length: 190)]
    private string $login;

    #[ORM\Column(length: 190)]
    private string $displayName;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 16, enumType: AccountKind::class)]
    private AccountKind $kind;

    #[ORM\Column(length: 32, enumType: EntryBasis::class)]
    private EntryBasis $entryBasis;

    /** @var list<string> the directory groups read at the last sign-in */
    #[ORM\Column(type: Types::JSON)]
    private array $directoryGroups = [];

    #[ORM\Column]
    private bool $administrator = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $administratorSince = null;

    /** Null for an administrator of the bootstrap list. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $administratorDesignatedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** @param list<string> $directoryGroups */
    public function __construct(string $directoryId, string $login, string $displayName, AccountKind $kind, EntryBasis $entryBasis, array $directoryGroups, \DateTimeImmutable $createdAt)
    {
        $this->directoryId = $directoryId;
        $this->login = $login;
        $this->displayName = $displayName;
        $this->kind = $kind;
        $this->entryBasis = $entryBasis;
        $this->directoryGroups = $directoryGroups;
        $this->createdAt = $createdAt;
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getKind(): AccountKind
    {
        return $this->kind;
    }

    public function isTeacher(): bool
    {
        return AccountKind::Teacher === $this->kind;
    }

    public function isStudent(): bool
    {
        return AccountKind::Student === $this->kind;
    }

    public function getEntryBasis(): EntryBasis
    {
        return $this->entryBasis;
    }

    /** @return list<string> */
    public function getDirectoryGroups(): array
    {
        return $this->directoryGroups;
    }

    /**
     * What the directory says at a sign-in: login (a rename), name, address, groups, and what the
     * entry rule made of them.
     *
     * @param list<string> $directoryGroups
     */
    public function refreshFromDirectory(string $login, string $displayName, ?string $email, array $directoryGroups, AccountKind $kind, EntryBasis $entryBasis): void
    {
        $this->login = $login;
        $this->displayName = $displayName;
        $this->email = $email;
        $this->directoryGroups = $directoryGroups;
        $this->kind = $kind;
        $this->entryBasis = $entryBasis;
        if (AccountKind::Teacher !== $kind) {
            // The flag is a teacher's (design-ux.md § 3.6), never a student's.
            $this->administrator = false;
            $this->administratorSince = null;
            $this->administratorDesignatedBy = null;
        }
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function isAdministrator(): bool
    {
        return $this->administrator && $this->isTeacher();
    }

    public function getAdministratorSince(): ?\DateTimeImmutable
    {
        return $this->administratorSince;
    }

    public function getAdministratorDesignatedBy(): ?User
    {
        return $this->administratorDesignatedBy;
    }

    /** Sets the flag; a teacher already administrator keeps their date and who designated them. */
    public function makeAdministrator(\DateTimeImmutable $since, ?User $designatedBy): void
    {
        if (!$this->isTeacher()) {
            throw new \LogicException('Only a teacher can be an administrator.');
        }
        if ($this->administrator) {
            return;
        }
        $this->administrator = true;
        $this->administratorSince = $since;
        $this->administratorDesignatedBy = $designatedBy;
    }

    public function removeAdministrator(): void
    {
        $this->administrator = false;
        $this->administratorSince = null;
        $this->administratorDesignatedBy = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function recordLogin(\DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
    }

    /** The stable id: a session survives a renamed login. */
    public function getUserIdentifier(): string
    {
        if ('' === $this->directoryId) {
            throw new \LogicException('An account always has a directory id.');
        }

        return $this->directoryId;
    }

    /** @return non-empty-list<string> */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER', $this->kind->role()];
        if ($this->isAdministrator()) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    public function eraseCredentials(): void
    {
    }
}
