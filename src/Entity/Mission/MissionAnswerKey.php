<?php

declare(strict_types=1);

namespace App\Entity\Mission;

use App\Entity\Dossier\Dossier;
use App\Entity\Identity\User;
use App\Repository\Mission\MissionAnswerKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A mission's answer key (step 2.9b; design-ux.md § 3.3 « Corrigé », conception § 6): the teacher
 * plays the mission in a copy of its template (`dossier`, kind AnswerKey, theirs alone), then
 * « Enregistrer comme corrigé » reads that copy into the data below, which the compared correction
 * (step 2.11) reads. **Never shown to a student**: named only where AnswerKeyStaysHomeTest allows.
 *
 * The recorded data - a stable shape, written by AnswerKeyReading only:
 *
 * - `entries`: every entry of the copy that its starting state did not hold (`baseline`: the
 *   fingerprints of the template's entries when the copy was made) - an entry written, or a draft of
 *   the template validated or changed -, in the copy's order (journal, date, id). Each: `journal`
 *   (code), `date` (Y-m-d), `document` (the pièce reference), `label`, `state` (EntryState value),
 *   `fingerprint` and `lines` - `account` (number), `thirdParty` (code, or null), `label`, `debit` and
 *   `credit` (cents), `optional` (a tolerance: the line may be missing).
 * - `pieces`: every pièce of the copy that has arrived: `number`, `type` (DocumentType value),
 *   `duplicate` (the second arrival of a doublon), `thirdParty` (code), `state` the teacher left it
 *   in (DocumentState value: posted, discarded…) and `entry` (index in `entries` of the entry it was
 *   posted to, null when none of them). What a pièce hides - the right read data, the slipped errors
 *   - stays on the pièce itself (Document::$answerKey, step 2.2b), copied with every dossier.
 * - Tolerances: `equivalentAccounts` (groups of account numbers accepted for one another) and
 *   `dateWindowDays` (an entry dated that many days before or after the expected date still matches).
 *
 * @phpstan-type ExpectedLine array{account: string, thirdParty: ?string, label: string, debit: int, credit: int, optional: bool}
 * @phpstan-type ExpectedEntry array{fingerprint: string, journal: string, date: string, document: string, label: string, state: string, lines: list<ExpectedLine>}
 * @phpstan-type ExpectedPiece array{number: string, type: string, duplicate: bool, thirdParty: ?string, state: string, entry: ?int}
 */
#[ORM\Entity(repositoryClass: MissionAnswerKeyRepository::class)]
#[ORM\Table(name: 'mission_answer_key')]
class MissionAnswerKey
{
    public const int DATE_WINDOW_MAX = 31;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Mission $mission;

    /** The copy the teacher plays the mission in; null once it was deleted (the record stays). */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(unique: true, onDelete: 'SET NULL')]
    private ?Dossier $dossier;

    /** @var list<string> fingerprints of the copy's entries when it was made, one per entry */
    #[ORM\Column(type: Types::JSON)]
    private array $baseline;

    /** @var list<ExpectedEntry> */
    #[ORM\Column(type: Types::JSON)]
    private array $entries = [];

    /** @var list<ExpectedPiece> */
    #[ORM\Column(type: Types::JSON)]
    private array $pieces = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recordedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $recordedBy = null;

    /** @var list<list<string>> */
    #[ORM\Column(type: Types::JSON)]
    private array $equivalentAccounts = [];

    #[ORM\Column(type: Types::SMALLINT)]
    private int $dateWindowDays = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $baseline */
    public function __construct(Mission $mission, Dossier $dossier, array $baseline, \DateTimeImmutable $createdAt)
    {
        $this->mission = $mission;
        $this->dossier = $dossier;
        $this->baseline = $baseline;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMission(): Mission
    {
        return $this->mission;
    }

    public function getDossier(): ?Dossier
    {
        return $this->dossier;
    }

    /** @return list<string> */
    public function getBaseline(): array
    {
        return $this->baseline;
    }

    /** @return list<ExpectedEntry> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /** @return list<ExpectedPiece> */
    public function getPieces(): array
    {
        return $this->pieces;
    }

    public function isRecorded(): bool
    {
        return null !== $this->recordedAt;
    }

    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    /** @return list<list<string>> */
    public function getEquivalentAccounts(): array
    {
        return $this->equivalentAccounts;
    }

    public function getDateWindowDays(): int
    {
        return $this->dateWindowDays;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @param list<string> $baseline */
    public function playIn(Dossier $dossier, array $baseline): void
    {
        $this->dossier = $dossier;
        $this->baseline = $baseline;
    }

    /**
     * @param list<ExpectedEntry> $entries
     * @param list<ExpectedPiece> $pieces
     */
    public function record(array $entries, array $pieces, User $by, \DateTimeImmutable $at): void
    {
        $this->entries = $entries;
        $this->pieces = $pieces;
        $this->recordedBy = $by;
        $this->recordedAt = $at;
    }

    /**
     * @param list<list<string>> $equivalentAccounts
     * @param list<ExpectedEntry> $entries            the same entries, their lines' `optional` set
     */
    public function tolerate(array $equivalentAccounts, int $dateWindowDays, array $entries): void
    {
        $this->equivalentAccounts = $equivalentAccounts;
        $this->dateWindowDays = $dateWindowDays;
        $this->entries = $entries;
    }
}
