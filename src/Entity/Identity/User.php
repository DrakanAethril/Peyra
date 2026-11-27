<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Enum\Identity\AccountKind;
use App\Enum\Identity\EntryBasis;
use App\Enum\Identity\PlacementOutcome;
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

    /** « Nom Prénom », how lists of people show and sort a person (design-ux.md § 6.6). */
    #[ORM\Column(length: 190)]
    private string $sortName;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 16, enumType: AccountKind::class)]
    private AccountKind $kind;

    #[ORM\Column(length: 32, enumType: EntryBasis::class)]
    private EntryBasis $entryBasis;

    /** @var list<string> the directory groups read at the last sign-in */
    #[ORM\Column(type: Types::JSON)]
    private array $directoryGroups = [];

    /**
     * The directory groups read when the account was created, never written again: the placement
     * at creation read them, and « À placer » shows them (conception § 3 bis).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $groupsAtCreation = [];

    /** What the placement at creation made of a student's groups; null for a teacher. */
    #[ORM\Column(length: 32, nullable: true, enumType: PlacementOutcome::class)]
    private ?PlacementOutcome $initialPlacement = null;

    /**
     * Set by hand when a student leaves the field (« Sorti de la filière »): they leave « À placer »,
     * keep their account and their dossiers. Cleared when they are placed again or sign in again.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $departedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $departedBy = null;

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
    public function __construct(string $directoryId, string $login, string $displayName, AccountKind $kind, EntryBasis $entryBasis, array $directoryGroups, \DateTimeImmutable $createdAt, ?string $sortName = null)
    {
        $this->directoryId = $directoryId;
        $this->login = $login;
        $this->displayName = $displayName;
        $this->sortName = $sortName ?? $displayName;
        $this->kind = $kind;
        $this->entryBasis = $entryBasis;
        $this->directoryGroups = $directoryGroups;
        $this->groupsAtCreation = $directoryGroups;
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

    public function getSortName(): string
    {
        return $this->sortName;
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

    /** @return list<string> */
    public function getGroupsAtCreation(): array
    {
        return $this->groupsAtCreation;
    }

    public function getInitialPlacement(): ?PlacementOutcome
    {
        return $this->initialPlacement;
    }

    /** Written once, by InitialPlacement, when the account is created. */
    public function recordInitialPlacement(PlacementOutcome $outcome): void
    {
        $this->initialPlacement = $outcome;
    }

    public function getDepartedAt(): ?\DateTimeImmutable
    {
        return $this->departedAt;
    }

    public function getDepartedBy(): ?User
    {
        return $this->departedBy;
    }

    public function isDeparted(): bool
    {
        return null !== $this->departedAt;
    }

    public function markDeparted(\DateTimeImmutable $at, User $by): void
    {
        if (!$this->isStudent()) {
            throw new \LogicException('Only a student leaves the field.');
        }
        $this->departedAt = $at;
        $this->departedBy = $by;
    }

    public function clearDeparture(): void
    {
        $this->departedAt = null;
        $this->departedBy = null;
    }

    /**
     * What the directory says at a sign-in: login (a rename), name, address, groups, and what the
     * entry rule made of them. The class is not read again (conception § 3 bis).
     *
     * @param list<string> $directoryGroups
     */
    public function refreshFromDirectory(string $login, string $displayName, ?string $email, array $directoryGroups, AccountKind $kind, EntryBasis $entryBasis, ?string $sortName = null): void
    {
        $this->login = $login;
        $this->displayName = $displayName;
        $this->sortName = $sortName ?? $displayName;
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
