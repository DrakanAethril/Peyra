<?php

declare(strict_types=1);

namespace App\Entity\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\AccountingBehaviour;
use App\Enum\Accounting\AssistanceLevel;
use App\Enum\Mission\MissionModule;
use App\Enum\Mission\MissionTaskCheck;
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
    /** A task's key: « t » and up to eight hexadecimal digits (« t1 » for the tasks of step 2.9a). */
    public const string TASK_KEY = '/^t[0-9a-f]{1,8}$/';

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

    /**
     * In their order. `key` names a task for good (« t3f9a01c2 »: what the student ticked follows it when the
     * tasks are reordered), `check` is what ticks it (MissionTaskCheck). Rows of step 2.9a have neither:
     * getTasks() reads them as « t<rank> », declared.
     *
     * @var list<array{key?: string, title: string, instruction: string, check?: string}>
     */
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

    /** @return list<array{key: string, title: string, instruction: string, check: string}> */
    public function getTasks(): array
    {
        $tasks = [];
        foreach ($this->tasks as $i => $task) {
            $check = MissionTaskCheck::tryFrom($task['check'] ?? '') ?? MissionTaskCheck::Declared;
            $tasks[] = ['key' => $task['key'] ?? 't'.($i + 1), 'title' => $task['title'], 'instruction' => $task['instruction'], 'check' => $check->value];
        }

        return $tasks;
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

    /**
     * A task without a key, or with one another task already holds, gets a new one, drawn at random
     * (« t3f9a01c2 »): a key is never given again, so a task removed takes what students ticked with it.
     *
     * @param array<array-key, array{key?: string, title: string, instruction: string, check?: string}> $tasks
     */
    public function setTasks(array $tasks): void
    {
        $rows = [];
        $taken = [];
        foreach (array_values($tasks) as $task) {
            $key = $task['key'] ?? '';
            while (1 !== preg_match(self::TASK_KEY, $key) || isset($taken[$key])) {
                $key = 't'.bin2hex(random_bytes(4));
            }
            $taken[$key] = true;
            $check = MissionTaskCheck::tryFrom($task['check'] ?? '') ?? MissionTaskCheck::Declared;
            $rows[] = ['key' => $key, 'title' => $task['title'], 'instruction' => $task['instruction'], 'check' => $check->value];
        }
        $this->tasks = $rows;
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
