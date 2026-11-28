<?php

declare(strict_types=1);

namespace App\Entity\Accounting;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Enum\Accounting\FecImportStage;
use App\Enum\Accounting\FecImportState;
use App\Repository\Accounting\FecImportJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A FEC being written into a new template in the background (step 1.10b; design-ux.md § 6.4): the
 * file - checked whole before the template was opened, kept compressed until the last pass -, the
 * template it fills (hidden while `importing`), the stage reached and how far into it, and what the
 * file brings, for the progress page. Global: it is a teacher's gesture on the library, never part
 * of a dossier; FecImportVoter gives it to its author alone.
 */
#[ORM\Entity(repositoryClass: FecImportJobRepository::class)]
#[ORM\Table(name: 'fec_import_job')]
class FecImportJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    /** Null once a teacher abandoned a stopped import (its template deleted with it). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Dossier $template;

    #[ORM\Column(length: 255)]
    private string $fileName;

    /** The file, gzip then base64, read again at each pass; emptied once the import is done. */
    #[ORM\Column(type: Types::TEXT, length: 16777215, nullable: true)]
    private ?string $payload;

    /** The exercise's first day the file was read against (FecImportReading). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $exerciseStart;

    #[ORM\Column(length: 16, enumType: FecImportState::class)]
    private FecImportState $state = FecImportState::Pending;

    #[ORM\Column(length: 16, enumType: FecImportStage::class)]
    private FecImportStage $stage = FecImportStage::Setup;

    /** How far into the stage: entries drafted or validated, lettering groups done. */
    #[ORM\Column(name: 'stage_position')]
    private int $position = 0;

    #[ORM\Column]
    private int $entries;

    #[ORM\Column]
    private int $journals;

    #[ORM\Column]
    private int $thirdParties;

    #[ORM\Column]
    private int $letterings;

    #[ORM\Column]
    private int $zeroLines;

    #[ORM\Column]
    private int $lettersLeft;

    #[ORM\Column]
    private int $accountsCreated = 0;

    /** Why it stopped, in the teacher's words. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $failure = null;

    /** Real time: when it was asked for, when its last pass ran. */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(User $author, Dossier $template, string $fileName, string $bytes, \DateTimeImmutable $exerciseStart, int $entries, int $journals, int $thirdParties, int $letterings, int $zeroLines, int $lettersLeft, \DateTimeImmutable $createdAt)
    {
        if (!$template->isTemplate() || !$template->isImporting()) {
            throw new \LogicException('A FEC is imported into a template opened for it.');
        }
        $this->author = $author;
        $this->template = $template;
        $this->fileName = mb_substr($fileName, 0, 255);
        $this->payload = base64_encode((string) gzencode($bytes, 6));
        $this->exerciseStart = $exerciseStart;
        $this->entries = $entries;
        $this->journals = $journals;
        $this->thirdParties = $thirdParties;
        $this->letterings = $letterings;
        $this->zeroLines = $zeroLines;
        $this->lettersLeft = $lettersLeft;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getTemplate(): ?Dossier
    {
        return $this->template;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    /** The file as it was sent. */
    public function bytes(): string
    {
        $bytes = null === $this->payload ? false : gzdecode((string) base64_decode($this->payload, true));
        if (false === $bytes) {
            throw new \LogicException('The file of a pending import is kept.');
        }

        return $bytes;
    }

    public function getExerciseStart(): \DateTimeImmutable
    {
        return $this->exerciseStart;
    }

    public function getState(): FecImportState
    {
        return $this->state;
    }

    public function isPending(): bool
    {
        return FecImportState::Pending === $this->state;
    }

    public function getStage(): FecImportStage
    {
        return $this->stage;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getEntries(): int
    {
        return $this->entries;
    }

    public function getJournals(): int
    {
        return $this->journals;
    }

    public function getThirdParties(): int
    {
        return $this->thirdParties;
    }

    public function getLetterings(): int
    {
        return $this->letterings;
    }

    public function getZeroLines(): int
    {
        return $this->zeroLines;
    }

    public function getLettersLeft(): int
    {
        return $this->lettersLeft;
    }

    public function getAccountsCreated(): int
    {
        return $this->accountsCreated;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** How many items the current stage counts: one for the setup, the entries, the lettering groups. */
    public function stageTotal(): int
    {
        return match ($this->stage) {
            FecImportStage::Setup => 1,
            FecImportStage::Drafts, FecImportStage::Validation => $this->entries,
            FecImportStage::Lettering => $this->letterings,
        };
    }

    /** The whole import as a percentage: the setup counts as one entry, every entry twice (written, validated), each lettering once. */
    public function percent(): int
    {
        if (FecImportState::Done === $this->state) {
            return 100;
        }
        $total = 1 + 2 * $this->entries + $this->letterings;
        $done = match ($this->stage) {
            FecImportStage::Setup => 0,
            FecImportStage::Drafts => 1 + $this->position,
            FecImportStage::Validation => 1 + $this->entries + $this->position,
            FecImportStage::Lettering => 1 + 2 * $this->entries + $this->position,
        };

        return intdiv(100 * $done, $total);
    }

    public function setUp(int $accountsCreated): void
    {
        $this->accountsCreated = $accountsCreated;
        $this->moveTo(FecImportStage::Drafts);
    }

    /** One more pass of the stage: `$count` items done; the next stage once the stage is through. */
    public function advance(int $count): void
    {
        $this->position += $count;
        if ($this->position >= $this->stageTotal()) {
            $this->moveTo(match ($this->stage) {
                FecImportStage::Setup => FecImportStage::Drafts,
                FecImportStage::Drafts => FecImportStage::Validation,
                FecImportStage::Validation, FecImportStage::Lettering => FecImportStage::Lettering,
            });
        }
    }

    public function isThrough(): bool
    {
        return FecImportStage::Lettering === $this->stage && $this->position >= $this->letterings;
    }

    public function finish(\DateTimeImmutable $at): void
    {
        $this->state = FecImportState::Done;
        $this->payload = null;
        $this->finishedAt = $at;
    }

    public function fail(string $why, \DateTimeImmutable $at): void
    {
        $this->state = FecImportState::Failed;
        $this->failure = mb_substr($why, 0, 500);
        $this->finishedAt = $at;
    }

    /** « Relancer »: the next pass starts again where the stopped one was rolled back. */
    public function retry(): void
    {
        if (FecImportState::Failed !== $this->state) {
            throw new \LogicException('Only a stopped import is taken up again.');
        }
        $this->state = FecImportState::Pending;
        $this->failure = null;
        $this->finishedAt = null;
    }

    private function moveTo(FecImportStage $stage): void
    {
        if ($stage !== $this->stage) {
            $this->stage = $stage;
            $this->position = 0;
        }
    }
}
