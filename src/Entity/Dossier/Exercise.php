<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Repository\Dossier\ExerciseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fiscal year of the dossier (« exercice »), cut into monthly periods (Period). Opened by
 * App\Service\Dossier\Exercises, which writes its periods with it; closed at the year-end closing
 * (lot 3), after which it refuses every entry.
 */
#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\Table(name: 'exercise')]
#[ORM\UniqueConstraint(name: 'uniq_exercise_dossier_code', columns: ['dossier_id', 'code'])]
class Exercise implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « 2026 », or « 2025-2026 » for an exercise across two calendar years. */
    #[ORM\Column(length: 16)]
    private string $code;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column]
    private bool $closed = false;

    public function __construct(Dossier $dossier, string $code, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn)
    {
        $this->dossier = $dossier;
        $this->code = $code;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function contains(\DateTimeImmutable $day): bool
    {
        $day = $day->format('Y-m-d');

        return $day >= $this->startsOn->format('Y-m-d') && $day <= $this->endsOn->format('Y-m-d');
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** The year-end closing (lot 3), and the installation of a dossier whose past exercise is already closed. */
    public function close(): void
    {
        $this->closed = true;
    }
}
