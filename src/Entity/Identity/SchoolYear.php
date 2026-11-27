<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Enum\Identity\SchoolYearState;
use App\Repository\Identity\SchoolYearRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A school year of Peyra (conception § 3 bis), « 2026-2027 ». An administrator creates it and
 * makes it the current one: classes, enrolments and the mapping with the directory are per year,
 * and the years before the current one are history. Exactly one is current once one exists
 * (SchoolYears holds that); nothing here reads the server clock.
 */
#[ORM\Entity(repositoryClass: SchoolYearRepository::class)]
#[ORM\Table(name: 'school_year')]
#[ORM\UniqueConstraint(name: 'uniq_school_year_starts_on', columns: ['starts_on'])]
class SchoolYear
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    #[ORM\Column(name: 'is_current')]
    private bool $current = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, \DateTimeImmutable $createdAt)
    {
        if ($endsOn <= $startsOn) {
            throw new \InvalidArgumentException('A school year ends after it starts.');
        }
        $this->startsOn = $startsOn;
        $this->endsOn = $endsOn;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    /** « 2026-2027 », or « 2026 » for a year that starts and ends in the same calendar year. */
    public function getLabel(): string
    {
        $start = $this->startsOn->format('Y');
        $end = $this->endsOn->format('Y');

        return $start === $end ? $start : $start.'-'.$end;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    /** Only SchoolYears calls these two: it takes the flag off the other years in the same gesture. */
    public function markCurrent(): void
    {
        $this->current = true;
    }

    public function unmarkCurrent(): void
    {
        $this->current = false;
    }

    /** Where this year stands against the current one (null: no year is current yet). */
    public function stateAgainst(?SchoolYear $current): SchoolYearState
    {
        if ($this->current) {
            return SchoolYearState::Current;
        }
        if (null !== $current && $this->startsOn < $current->getStartsOn()) {
            return SchoolYearState::Past;
        }

        return SchoolYearState::Upcoming;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
