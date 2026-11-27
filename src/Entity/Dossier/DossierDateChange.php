<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\User;
use App\Entity\Shared\AppendOnly;
use App\Repository\Dossier\DossierDateChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One move of the dossier's date (design-ux.md § 2.1, « les trois derniers changements » of the
 * date chip): from which day to which, who moved it and when in real time. Written by
 * App\Service\Dossier\DossierDates alone, never changed nor deleted.
 */
#[ORM\Entity(repositoryClass: DossierDateChangeRepository::class)]
#[ORM\Table(name: 'dossier_date_change')]
#[ORM\Index(name: 'idx_dossier_date_change_at', columns: ['dossier_id', 'changed_at'])]
class DossierDateChange implements DossierOwned, AppendOnly
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $fromDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $toDate;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $changedBy;

    /** The person's name at the time, or the command's: kept if the account goes. */
    #[ORM\Column(length: 190)]
    private string $actorName;

    public function __construct(Dossier $dossier, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate, \DateTimeImmutable $changedAt, User|string $actor)
    {
        $this->dossier = $dossier;
        $this->fromDate = $fromDate->setTime(0, 0);
        $this->toDate = $toDate->setTime(0, 0);
        $this->changedAt = $changedAt;
        $this->changedBy = $actor instanceof User ? $actor : null;
        $this->actorName = $actor instanceof User ? $actor->getDisplayName() : $actor;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromDate(): \DateTimeImmutable
    {
        return $this->fromDate;
    }

    public function getToDate(): \DateTimeImmutable
    {
        return $this->toDate;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function getActorName(): string
    {
        return $this->actorName;
    }
}
