<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Enum\Dossier\PeriodState;
use App\Repository\Dossier\PeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A month of an exercise (conception § 5): open, it takes entries; closed, it refuses them. Its
 * bounds come from App\Service\Dossier\PeriodPlan - a calendar month, cut by the exercise's first
 * and last day.
 */
#[ORM\Entity(repositoryClass: PeriodRepository::class)]
#[ORM\Table(name: 'period')]
#[ORM\UniqueConstraint(name: 'uniq_period_exercise_start', columns: ['exercise_id', 'starts_on'])]
class Period implements DossierOwned
{
    use DossierOwnedTrait;

    private const array MONTHS = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Exercise $exercise;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(length: 8, enumType: PeriodState::class)]
    private PeriodState $state = PeriodState::Open;

    public function __construct(Exercise $exercise, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn)
    {
        $this->dossier = $exercise->getDossier();
        $this->exercise = $exercise;
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExercise(): Exercise
    {
        return $this->exercise;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    /** « Novembre 2026 » */
    public function getLabel(): string
    {
        return self::MONTHS[(int) $this->startsOn->format('n')].' '.$this->startsOn->format('Y');
    }

    public function getState(): PeriodState
    {
        return $this->state;
    }

    public function isClosed(): bool
    {
        return PeriodState::Closed === $this->state;
    }

    public function close(): void
    {
        $this->state = PeriodState::Closed;
    }
}
