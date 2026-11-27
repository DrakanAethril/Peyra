<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Repository\Identity\TeacherClassRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A teacher of a class of the year (conception § 3 bis): « Mes classes », and later the reading of
 * every dossier of the class's students. An administrator sets it in Administration › Classes.
 */
#[ORM\Entity(repositoryClass: TeacherClassRepository::class)]
#[ORM\Table(name: 'teacher_class')]
#[ORM\UniqueConstraint(name: 'uniq_teacher_class', columns: ['teacher_id', 'school_class_id'])]
class TeacherClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $teacher;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolClass $schoolClass;

    #[ORM\Column]
    private \DateTimeImmutable $addedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $addedBy;

    public function __construct(User $teacher, SchoolClass $schoolClass, \DateTimeImmutable $addedAt, ?User $addedBy)
    {
        if (!$teacher->isTeacher()) {
            throw new \LogicException('Only a teacher teaches a class.');
        }
        $this->teacher = $teacher;
        $this->schoolClass = $schoolClass;
        $this->addedAt = $addedAt;
        $this->addedBy = $addedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getSchoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }
}
