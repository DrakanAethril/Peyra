<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\SchoolClass;
use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionMode;
use App\Repository\Dossier\DistributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A template handed out to students of a class (lot 1, step 1.8; design-ux.md § 4.2): a dossier
 * for each student, or one for each group, made one by one in the background (DistributionCopy).
 * Global: it says where dossiers came from and is never part of one. It is its author's
 * (DistributionVoter); withdrawing it deletes its dossiers, a teacher's gesture, never automatic.
 */
#[ORM\Entity(repositoryClass: DistributionRepository::class)]
#[ORM\Table(name: 'distribution')]
class Distribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null once the template is deleted: the dossiers keep their content (DECISIONS 1.8a). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Dossier $template;

    /** The template's title in the library when it was handed out. */
    #[ORM\Column(length: 120)]
    private string $title;

    /** Null when a command made it (app:dev:dossiers). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private SchoolClass $schoolClass;

    #[ORM\Column(length: 16, enumType: DistributionMode::class)]
    private DistributionMode $mode;

    /** The date each dossier starts at. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    /** Real time: when it was asked for. */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Dossier $template, ?User $author, SchoolClass $schoolClass, DistributionMode $mode, \DateTimeImmutable $startDate, \DateTimeImmutable $createdAt)
    {
        if (!$template->isTemplate() || (null !== $author && !$author->isTeacher())) {
            throw new \LogicException('A teacher hands out a template.');
        }
        $this->template = $template;
        $this->title = mb_substr($template->getLibraryTitle(), 0, 120);
        $this->author = $author;
        $this->schoolClass = $schoolClass;
        $this->mode = $mode;
        $this->startDate = $startDate->setTime(0, 0);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?Dossier
    {
        return $this->template;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getSchoolClass(): SchoolClass
    {
        return $this->schoolClass;
    }

    public function getMode(): DistributionMode
    {
        return $this->mode;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
