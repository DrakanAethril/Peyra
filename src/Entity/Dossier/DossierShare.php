<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\User;
use App\Repository\Dossier\DossierShareRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A template its author shares with a colleague (conception § 4, « lecture ou modification »). The
 * library screens that write it come with step 1.8; DossierVoter reads it now. Like a member, a
 * share says who enters, not what the dossier holds: global, never copied.
 */
#[ORM\Entity(repositoryClass: DossierShareRepository::class)]
#[ORM\Table(name: 'dossier_share')]
#[ORM\UniqueConstraint(name: 'uniq_dossier_share', columns: ['dossier_id', 'teacher_id'])]
class DossierShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Dossier $dossier;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $teacher;

    /** False: the colleague reads; true: they change it too. */
    #[ORM\Column]
    private bool $canEdit;

    #[ORM\Column]
    private \DateTimeImmutable $sharedAt;

    public function __construct(Dossier $dossier, User $teacher, bool $canEdit, \DateTimeImmutable $sharedAt)
    {
        if (!$dossier->isTemplate() || !$teacher->isTeacher()) {
            throw new \LogicException('A template is shared with teachers.');
        }
        $this->dossier = $dossier;
        $this->teacher = $teacher;
        $this->canEdit = $canEdit;
        $this->sharedAt = $sharedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function canEdit(): bool
    {
        return $this->canEdit;
    }

    public function getSharedAt(): \DateTimeImmutable
    {
        return $this->sharedAt;
    }
}
