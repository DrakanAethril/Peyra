<?php

declare(strict_types=1);

namespace App\Entity\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Mission\MissionModule;
use App\Repository\Mission\MissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mission of the library (step 2.9a; conception § 4, design-ux.md § 3.3 « Mission (composition) »):
 * a teacher's, tied to the template it is played on - its pièces are that template's, with the
 * dossier's date each arrives on -, with its statement, its ordered tasks, the activities of the
 * référentiel it aims at, the modules it opens and its two settings (design-ux.md § 3.4). Given to a
 * distribution of its template (MissionAssignment), it sets them for that distribution's dossiers.
 * Written by MissionSheet alone.
 */
#[ORM\Entity(repositoryClass: MissionRepository::class)]
#[ORM\Table(name: 'mission')]
class Mission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Dossier $template;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $statement = '';

    /** @var list<array{title: string, instruction: string}> in their order */
    #[ORM\Column(type: Types::JSON)]
    private array $tasks = [];

    /** @var list<string> codes of the référentiel's activities (« A1.2 ») */
    #[ORM\Column(type: Types::JSON)]
    private array $activities = [];

    /** @var list<string> MissionModule values */
    #[ORM\Column(type: Types::JSON)]
    private array $modules = [];

    #[ORM\Column(length: 16, enumType: AssistanceLevel::class)]
    private AssistanceLevel $assistanceLevel;

    #[ORM\Column(length: 16, enumType: AccountingBehaviour::class)]
    private AccountingBehaviour $behaviour;

    /** « Saisie guidée ouverte » (design-ux.md § 3.2): the guided entry offered at A and 0 too. */
    #[ORM\Column]
    private bool $guidedEntryOpen = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<MissionModule> $modules */
    public function __construct(Dossier $template, User $author, string $title, array $modules, AssistanceLevel $level, AccountingBehaviour $behaviour, \DateTimeImmutable $at)
    {
        $this->template = $template;
        $this->author = $author;
        $this->title = $title;
        $this->modules = array_map(static fn (MissionModule $module): string => $module->value, $modules);
        $this->assistanceLevel = $level;
        $this->behaviour = $behaviour;
        $this->createdAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** The template it is played on; null once that template was deleted. */
    public function getTemplate(): ?Dossier
    {
        return $this->template;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatement(): string
    {
        return $this->statement;
    }

    /** @return list<array{title: string, instruction: string}> */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /** @return list<string> */
    public function getActivities(): array
    {
        return $this->activities;
    }

    /** @return list<MissionModule> in the enum's order */
    public function getModules(): array
    {
        return array_values(array_filter(MissionModule::cases(), fn (MissionModule $module): bool => \in_array($module->value, $this->modules, true)));
    }

    public function opens(MissionModule $module): bool
    {
        return \in_array($module->value, $this->modules, true);
    }

    public function getAssistanceLevel(): AssistanceLevel
    {
        return $this->assistanceLevel;
    }

    public function getBehaviour(): AccountingBehaviour
    {
        return $this->behaviour;
    }

    public function isGuidedEntryOpen(): bool
    {
        return $this->guidedEntryOpen;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @param list<MissionModule> $modules */
    public function describe(string $title, string $statement, array $modules): void
    {
        $this->title = $title;
        $this->statement = $statement;
        $this->modules = array_map(static fn (MissionModule $module): string => $module->value, $modules);
    }

    /** @param array<array-key, array{title: string, instruction: string}> $tasks */
    public function setTasks(array $tasks): void
    {
        $this->tasks = array_values($tasks);
    }

    /** @param array<array-key, string> $activities */
    public function aimAt(array $activities): void
    {
        $this->activities = array_values($activities);
    }

    public function settle(AssistanceLevel $level, AccountingBehaviour $behaviour, bool $guidedEntryOpen): void
    {
        $this->assistanceLevel = $level;
        $this->behaviour = $behaviour;
        $this->guidedEntryOpen = $guidedEntryOpen;
    }
}
