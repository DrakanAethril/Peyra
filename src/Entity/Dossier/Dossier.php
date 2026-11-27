<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\SchoolYear;
use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fictitious company kept in Peyra (conception § 4): the unit every business row belongs to
 * (DossierOwned), opened at `/d/{dossier}` once DossierVoter lets the person in.
 *
 * **A dossier belongs to the student, not to the class** (conception § 3 bis): a student's dossier
 * names its owner, a group's dossier its members (DossierMember), a template its author - or nobody,
 * for the common library the administrators keep. Its school year is the one it was made in, which
 * « Mes dossiers » groups by; it never moves with the student's class.
 *
 * **The dossier's date** is its own clock (DossierClock), moved by a teacher (DossierDates): no
 * business calculation reads the server's. The real times kept here (`createdAt`, `dateMovedAt`,
 * `lastVisitedAt`) serve the audit trail and the screens, never a calculation.
 */
#[ORM\Entity(repositoryClass: DossierRepository::class)]
#[ORM\Table(name: 'dossier')]
#[ORM\Index(name: 'idx_dossier_kind', columns: ['kind'])]
class Dossier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: DossierKind::class)]
    private DossierKind $kind;

    #[ORM\Embedded(class: Company::class)]
    private Company $company;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dossierDate;

    /** The real time the date last moved. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateMovedAt = null;

    /** The date moved and no student holding the dossier has opened it since: their chip flashes once. */
    #[ORM\Column]
    private bool $dateMoveUnseen = false;

    /** The student of a student's dossier, the author of a template; null for a group's dossier and the common library. */
    #[ORM\ManyToOne]
    private ?User $owner;

    /** The school year the dossier was made in; « Mes dossiers » groups by it. */
    #[ORM\ManyToOne]
    private ?SchoolYear $schoolYear;

    /** The template it was copied from (origin), if any. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Dossier $sourceTemplate = null;

    /**
     * The teacher who distributed it (origin): they keep reading the missions they gave, even once
     * they no longer teach the student (conception § 3 bis).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $distributedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Null when a command made it (app:demo:install). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy;

    /** The last time its student (or a member of its group) opened it: « dernier passage ». */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastVisitedAt = null;

    public function __construct(DossierKind $kind, Company $company, \DateTimeImmutable $dossierDate, ?User $owner, ?SchoolYear $schoolYear, \DateTimeImmutable $createdAt, ?User $createdBy)
    {
        $fits = match ($kind) {
            DossierKind::Student => null !== $owner && $owner->isStudent(),
            DossierKind::Template => null === $owner || $owner->isTeacher(),
            DossierKind::Group, DossierKind::Snapshot => null === $owner,
        };
        if (!$fits) {
            throw new \LogicException('A student’s dossier belongs to a student, a template to a teacher or to nobody, a group’s dossier and a restore point to nobody.');
        }
        $this->kind = $kind;
        $this->company = $company;
        $this->dossierDate = $dossierDate->setTime(0, 0);
        $this->owner = $owner;
        $this->schoolYear = $schoolYear;
        $this->createdAt = $createdAt;
        $this->createdBy = $createdBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): DossierKind
    {
        return $this->kind;
    }

    public function isTemplate(): bool
    {
        return DossierKind::Template === $this->kind;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    /** CompanySheet checks the fiche before it is replaced. */
    public function describeCompany(Company $company): void
    {
        $this->company = $company;
    }

    /** « Menuiserie Vasseur SARL » */
    public function getName(): string
    {
        return $this->company->getName();
    }

    public function getDossierDate(): \DateTimeImmutable
    {
        return $this->dossierDate;
    }

    public function getDateMovedAt(): ?\DateTimeImmutable
    {
        return $this->dateMovedAt;
    }

    /** DossierDates is the one caller: it writes the change to the dossier's history and the audit trail. */
    public function moveDate(\DateTimeImmutable $to, \DateTimeImmutable $at): void
    {
        $this->dossierDate = $to->setTime(0, 0);
        $this->dateMovedAt = $at;
        $this->dateMoveUnseen = true;
    }

    public function isDateMoveUnseen(): bool
    {
        return $this->dateMoveUnseen;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getSchoolYear(): ?SchoolYear
    {
        return $this->schoolYear;
    }

    public function getSourceTemplate(): ?Dossier
    {
        return $this->sourceTemplate;
    }

    public function getDistributedBy(): ?User
    {
        return $this->distributedBy;
    }

    /** The origin of a copy: the template it comes from and the teacher who handed it out. */
    public function comesFrom(?Dossier $template, ?User $distributedBy): void
    {
        if (null !== $template && !$template->isTemplate()) {
            throw new \LogicException('A dossier is copied from a template.');
        }
        $this->sourceTemplate = $template;
        $this->distributedBy = $distributedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getLastVisitedAt(): ?\DateTimeImmutable
    {
        return $this->lastVisitedAt;
    }

    /** A student holding it opened it: « dernier passage », and the move of its date is seen. */
    public function visited(\DateTimeImmutable $at): void
    {
        $this->lastVisitedAt = $at;
        $this->dateMoveUnseen = false;
    }
}
