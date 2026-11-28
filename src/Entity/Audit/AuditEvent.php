<?php

declare(strict_types=1);

namespace App\Entity\Audit;

use App\Entity\Identity\User;
use App\Entity\Shared\AppendOnly;
use App\Enum\Audit\AuditAction;
use App\Enum\Audit\AuditActorRole;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Audit\AuditEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the audit trail (conception § 4: « qui, quoi, quand »): written once by
 * App\Service\Audit\AuditTrail, never changed nor deleted (AppendOnly). The real time is the
 * trail's own, the only business place that reads the clock. The author's name is copied, so the
 * line reads the same after a rename; a command or the scheduler signs with its own name and no
 * account. The subject is a type and an identifier (« regulatory_key », « social.smic_hourly »),
 * so a screen reads the history of one thing.
 *
 * A gesture in a dossier (AuditTrail::inDossier()) also carries the dossier, its date at the time
 * (« quand : réel et date du dossier »), what its author was - teacher or student - and their
 * profile in a group's dossier: the trail of the dossier (Système d'information › Piste d'audit)
 * reads them. The dossier is an identifier without a foreign key: the trail outlives the dossier,
 * and the copier (1.8) never copies it - a copy starts a trail of its own.
 */
#[ORM\Entity(repositoryClass: AuditEventRepository::class)]
#[ORM\Index(name: 'idx_audit_event_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_audit_event_dossier', columns: ['dossier_id', 'id'])]
#[ORM\Index(name: 'idx_audit_event_occurred', columns: ['occurred_at'])]
class AuditEvent implements AppendOnly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $actor;

    #[ORM\Column(length: 190)]
    private string $actorName;

    #[ORM\Column(length: 60, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 60)]
    private string $subjectType;

    #[ORM\Column(length: 190)]
    private string $subjectId;

    /** The line as a person reads it, in French (« SMIC horaire brut : 12,31 €/heure au 01/06/2026 »). */
    #[ORM\Column(length: 255)]
    private string $summary;

    /** @var array<string, mixed> what the line carries beyond its sentence */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    #[ORM\Column(nullable: true)]
    private ?int $dossierId = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dossierDate = null;

    #[ORM\Column(length: 20, nullable: true, enumType: AuditActorRole::class)]
    private ?AuditActorRole $actorRole = null;

    #[ORM\Column(length: 30, nullable: true, enumType: DossierProfile::class)]
    private ?DossierProfile $actorProfile = null;

    /** @param array<string, mixed> $details */
    public function __construct(\DateTimeImmutable $occurredAt, ?User $actor, string $actorName, AuditAction $action, string $subjectType, string $subjectId, string $summary, array $details)
    {
        $this->occurredAt = $occurredAt;
        $this->actor = $actor;
        $this->actorName = $actorName;
        $this->action = $action;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->summary = $summary;
        $this->details = $details;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorName(): string
    {
        return $this->actorName;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** Set once, by AuditTrail::inDossier(), before the line is persisted. */
    public function placeInDossier(int $dossierId, \DateTimeImmutable $dossierDate, ?AuditActorRole $role, ?DossierProfile $profile): void
    {
        if (null !== $this->dossierId) {
            throw new \LogicException('A line of the trail belongs to one dossier.');
        }
        $this->dossierId = $dossierId;
        $this->dossierDate = $dossierDate;
        $this->actorRole = $role;
        $this->actorProfile = $profile;
    }

    public function getDossierId(): ?int
    {
        return $this->dossierId;
    }

    /** The dossier's date when the gesture was made; null for a line written before step 1.7. */
    public function getDossierDate(): ?\DateTimeImmutable
    {
        return $this->dossierDate;
    }

    public function getActorRole(): ?AuditActorRole
    {
        return $this->actorRole;
    }

    public function getActorProfile(): ?DossierProfile
    {
        return $this->actorProfile;
    }
}
