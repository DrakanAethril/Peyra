<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Repository\Identity\DirectoryGroupMappingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Ce groupe de l'annuaire place dans cette classe » for one school year (conception § 3 bis:
 * `cg-1` → CG1, `cg-2` → CG2), read once, when a student's account is created. A group with no row
 * places nobody.
 */
#[ORM\Entity(repositoryClass: DirectoryGroupMappingRepository::class)]
#[ORM\Table(name: 'directory_group_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_directory_group_mapping', columns: ['school_year_id', 'directory_group'])]
class DirectoryGroupMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SchoolYear $schoolYear;

    /** The group's `cn`, lower case, as the directory reading returns it. */
    #[ORM\Column(length: 64)]
    private string $directoryGroup;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolClass $schoolClass;

    public function __construct(string $directoryGroup, SchoolClass $schoolClass)
    {
        $this->schoolYear = $schoolClass->getSchoolYear();
        $this->directoryGroup = mb_strtolower($directoryGroup);
        $this->schoolClass = $schoolClass;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getDirectoryGroup(): string
    {
        return $this->directoryGroup;
    }

    public function getSchoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    public function pointTo(SchoolClass $schoolClass): void
    {
        if ($schoolClass->getSchoolYear() !== $this->schoolYear) {
            throw new \LogicException('A mapping points to a class of its own year.');
        }
        $this->schoolClass = $schoolClass;
    }
}
