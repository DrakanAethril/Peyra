<?php

declare(strict_types=1);

namespace App\Entity\Identity;

use App\Enum\Identity\EnrolmentOrigin;
use App\Repository\Identity\EnrolmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A student in a class for one school year (conception § 3 bis): one per student and year, those
 * of the years past being the history. Written by Enrolments alone; moving a student within the
 * year changes the class of this row, and the gesture goes to the administration journal.
 */
#[ORM\Entity(repositoryClass: EnrolmentRepository::class)]
#[ORM\Table(name: 'enrolment')]
#[ORM\UniqueConstraint(name: 'uniq_enrolment_student_year', columns: ['student_id', 'school_year_id'])]
class Enrolment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $student;

    /** The class's year, repeated so that the database itself holds « one enrolment per year ». */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SchoolYear $schoolYear;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SchoolClass $schoolClass;

    #[ORM\Column(length: 16, enumType: EnrolmentOrigin::class)]
    private EnrolmentOrigin $origin;

    #[ORM\Column]
    private \DateTimeImmutable $enrolledAt;

    /** Null when the directory placed the student at the creation of their account. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $enrolledBy;

    public function __construct(User $student, SchoolClass $schoolClass, EnrolmentOrigin $origin, \DateTimeImmutable $enrolledAt, ?User $enrolledBy)
    {
        if (!$student->isStudent()) {
            throw new \LogicException('Only a student is enrolled in a class.');
        }
        $this->student = $student;
        $this->schoolClass = $schoolClass;
        $this->schoolYear = $schoolClass->getSchoolYear();
        $this->origin = $origin;
        $this->enrolledAt = $enrolledAt;
        $this->enrolledBy = $enrolledBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getSchoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    public function getOrigin(): EnrolmentOrigin
    {
        return $this->origin;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }

    public function getEnrolledBy(): ?User
    {
        return $this->enrolledBy;
    }

    /** Another class of the same year, by hand. */
    public function moveTo(SchoolClass $schoolClass, \DateTimeImmutable $at, User $by): void
    {
        if ($schoolClass->getSchoolYear() !== $this->schoolYear) {
            throw new \LogicException('A student moves within the year of their enrolment.');
        }
        $this->schoolClass = $schoolClass;
        $this->origin = EnrolmentOrigin::Manual;
        $this->enrolledAt = $at;
        $this->enrolledBy = $by;
    }
}
