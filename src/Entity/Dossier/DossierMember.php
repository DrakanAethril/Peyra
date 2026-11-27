<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\User;
use App\Enum\Dossier\DossierKind;
use App\Repository\Dossier\DossierMemberRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student who works in a group's dossier (conception § 4, « un dossier par groupe »). Their
 * profile in it (comptable fournisseurs, chef comptable…) joins at step 1.7. Who enters a dossier
 * is not part of its content: the row is global, read by DossierVoter before any context exists,
 * and never copied with the dossier.
 */
#[ORM\Entity(repositoryClass: DossierMemberRepository::class)]
#[ORM\Table(name: 'dossier_member')]
#[ORM\UniqueConstraint(name: 'uniq_dossier_member', columns: ['dossier_id', 'student_id'])]
class DossierMember
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
    private User $student;

    #[ORM\Column]
    private \DateTimeImmutable $addedAt;

    public function __construct(Dossier $dossier, User $student, \DateTimeImmutable $addedAt)
    {
        if (DossierKind::Group !== $dossier->getKind() || !$student->isStudent()) {
            throw new \LogicException('A group’s dossier has students for members.');
        }
        $this->dossier = $dossier;
        $this->student = $student;
        $this->addedAt = $addedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDossier(): Dossier
    {
        return $this->dossier;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
