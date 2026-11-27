<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Enum\Identity\ClassLevel;
use App\Repository\Identity\SchoolClassRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A class of one school year (conception § 3 bis), « BTS CG 1 · 2026-2027 ». It names the class
 * its students move on to the next year (CG1 2026-2027 → CG2 2027-2028), which the « passage de
 * classe » proposes. A class holds no dossier: a dossier is its student's.
 */
#[ORM\Entity(repositoryClass: SchoolClassRepository::class)]
#[ORM\Table(name: 'school_class')]
#[ORM\UniqueConstraint(name: 'uniq_school_class_year_name', columns: ['school_year_id', 'name'])]
class SchoolClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SchoolYear $schoolYear;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(type: 'smallint', enumType: ClassLevel::class)]
    private ClassLevel $level;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?SchoolClass $nextClass = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(SchoolYear $schoolYear, string $name, ClassLevel $level, \DateTimeImmutable $createdAt)
    {
        $this->schoolYear = $schoolYear;
        $this->name = $name;
        $this->level = $level;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** « BTS CG 1 · 2026-2027 » */
    public function getLabel(): string
    {
        return $this->name.' · '.$this->schoolYear->getLabel();
    }

    public function getLevel(): ClassLevel
    {
        return $this->level;
    }

    public function rename(string $name, ClassLevel $level): void
    {
        $this->name = $name;
        $this->level = $level;
    }

    public function getNextClass(): ?SchoolClass
    {
        return $this->nextClass;
    }

    /** SchoolClasses checks the next class belongs to a later year. */
    public function setNextClass(?SchoolClass $nextClass): void
    {
        $this->nextClass = $nextClass;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
