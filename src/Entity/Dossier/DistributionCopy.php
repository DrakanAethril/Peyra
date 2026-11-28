<?php

declare(strict_types=1);

namespace App\Entity\Dossier;

use App\Entity\Identity\User;
use App\Enum\Dossier\DistributionCopyState;
use App\Enum\Dossier\DistributionMode;
use App\Enum\Dossier\DossierProfile;
use App\Repository\Dossier\DistributionCopyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One dossier of a distribution (design-ux.md § 4.2, step 3): a student's, or a group's with its
 * members and their profiles, and where its copy stands - waiting, copying, ready (its dossier
 * named), failed (why). Made by App\Service\Dossier\Distribution\DistributionRunner, one at a
 * time under its own lock; a failed one goes back to waiting when « Relancer » is asked.
 *
 * A group's members are kept as student ids with their profile until the copy makes them the
 * dossier's members (DossierMember): the copy is not a dossier yet.
 */
#[ORM\Entity(repositoryClass: DistributionCopyRepository::class)]
#[ORM\Table(name: 'distribution_copy')]
#[ORM\Index(name: 'idx_distribution_copy_state', columns: ['state'])]
class DistributionCopy
{
    public const int GROUP_NAME_MAX = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Distribution $distribution;

    /** The student of an individual dossier. */
    #[ORM\ManyToOne]
    private ?User $student;

    /** « Groupe 1 », for a group's dossier. */
    #[ORM\Column(length: self::GROUP_NAME_MAX, nullable: true)]
    private ?string $groupName;

    /** @var list<array{student: int, profile: string|null}> a group's members, in the order given */
    #[ORM\Column(type: Types::JSON)]
    private array $members;

    /** The dossier once made; null before, and after a withdrawal deleted it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Dossier $dossier = null;

    #[ORM\Column(length: 16, enumType: DistributionCopyState::class)]
    private DistributionCopyState $state = DistributionCopyState::Waiting;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failure = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param list<array{0: User, 1: DossierProfile|null}> $members a group's
     */
    private function __construct(Distribution $distribution, ?User $student, ?string $groupName, array $members)
    {
        $this->distribution = $distribution;
        $this->student = $student;
        $this->groupName = $groupName;
        $this->members = array_map(static fn (array $member): array => [
            'student' => $member[0]->getId() ?? throw new \LogicException('A saved student is a member.'),
            'profile' => $member[1]?->value,
        ], $members);
    }

    public static function forStudent(Distribution $distribution, User $student): self
    {
        if (DistributionMode::Individual !== $distribution->getMode() || !$student->isStudent()) {
            throw new \LogicException('An individual distribution gives a dossier to a student.');
        }

        return new self($distribution, $student, null, []);
    }

    /** @param list<array{0: User, 1: DossierProfile|null}> $members */
    public static function forGroup(Distribution $distribution, string $name, array $members): self
    {
        $name = trim($name);
        if (DistributionMode::Group !== $distribution->getMode() || [] === $members || '' === $name || mb_strlen($name) > self::GROUP_NAME_MAX) {
            throw new \LogicException('A group’s dossier has a name and members.');
        }
        foreach ($members as [$student]) {
            if (!$student->isStudent()) {
                throw new \LogicException('A group’s members are students.');
            }
        }

        return new self($distribution, null, $name, $members);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDistribution(): Distribution
    {
        return $this->distribution;
    }

    public function getStudent(): ?User
    {
        return $this->student;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    /** @return list<array{student: int, profile: DossierProfile|null}> */
    public function getMembers(): array
    {
        return array_map(static fn (array $member): array => [
            'student' => $member['student'],
            'profile' => null === $member['profile'] ? null : DossierProfile::from($member['profile']),
        ], $this->members);
    }

    /** @return list<int> every student the dossier is for */
    public function studentIds(): array
    {
        if (null !== $this->student) {
            return [$this->student->getId() ?? 0];
        }

        return array_map(static fn (array $member): int => $member['student'], $this->members);
    }

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    public function getState(): DistributionCopyState
    {
        return $this->state;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markCopying(): void
    {
        if (!$this->state->isPending()) {
            throw new \LogicException('Only a copy still to make is being made.');
        }
        $this->state = DistributionCopyState::Copying;
    }

    public function markReady(Dossier $dossier, \DateTimeImmutable $at): void
    {
        if (!$this->state->isPending()) {
            throw new \LogicException('Only a copy still to make becomes ready.');
        }
        $this->dossier = $dossier;
        $this->state = DistributionCopyState::Ready;
        $this->failure = null;
        $this->finishedAt = $at;
    }

    public function markFailed(string $reason, \DateTimeImmutable $at): void
    {
        if (!$this->state->isPending()) {
            throw new \LogicException('Only a copy still to make fails.');
        }
        $this->state = DistributionCopyState::Failed;
        $this->failure = mb_substr($reason, 0, 255);
        $this->finishedAt = $at;
    }

    /** « Relancer »: a failed copy waits again. */
    public function retry(): void
    {
        if (DistributionCopyState::Failed !== $this->state) {
            throw new \LogicException('Only a failed copy is made again.');
        }
        $this->state = DistributionCopyState::Waiting;
        $this->failure = null;
        $this->finishedAt = null;
    }
}
