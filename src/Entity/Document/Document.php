<?php

declare(strict_types=1);

namespace App\Entity\Document;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\Identity\User;
use App\Entity\Shared\DossierFileKey;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Document\AnomalyOutcome;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Repository\Document\DocumentRepository;
use App\Service\Document\PieceAnomaly;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pièce of the dossier (lot 2, step 2.1): its nature, its file (a PDF or a picture, kept by
 * DossierFiles under the dossier's prefix), where it stands, and its **read data** - what the
 * tool « read » on it: third party, number, date, due date, order reference, VAT breakdown by rate
 * and the three totals (design-ux.md § 3.5). The read data are read, never computed: a pièce whose
 * total is wrong keeps the wrong total. Documents is the only writer.
 *
 * Its processing (step 2.2a) adds the entry proposed from the read data, kept on the pièce and
 * distinct from the entry written (conception, « Piste A »), the entry written in the brouillard when
 * the pièce is posted, and the anomalies the student reported. Other entries carrying its number as
 * their pièce reference are read (the sheet).
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_document_dossier_state', columns: ['dossier_id', 'state'])]
class Document implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, enumType: DocumentType::class)]
    private DocumentType $type;

    #[ORM\Column(length: 16, enumType: DocumentState::class)]
    private DocumentState $state = DocumentState::ToProcess;

    /** `dossiers/12/pieces/<random>.pdf` */
    #[ORM\Column(length: 255)]
    #[DossierFileKey]
    private string $fileKey;

    /** The file's name as it was deposited (« FA-2611-0457.pdf »). */
    #[ORM\Column(length: 255)]
    private string $fileName;

    /** application/pdf, image/jpeg, image/png or image/webp: read from the bytes at the deposit. */
    #[ORM\Column(length: 40)]
    private string $mediaType;

    /** The dossier's date the pièce arrived on (never the computer's). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $receivedOn;

    /** Who deposited it; null for what the application made. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $depositedBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ThirdParty $thirdParty = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $documentDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $orderReference = null;

    /** @var list<array{rate: string, base: int, vat: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $vatLines = [];

    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $totalExcludingTax = null;

    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $totalVat = null;

    #[ORM\Column(type: 'money', nullable: true)]
    private ?Money $totalIncludingTax = null;

    /**
     * What the generator made it from (step 2.1b: InvoiceOperation::toArray(), plus `copy`, 2 for
     * a « doublon »'s second arrival), the faults asked for included; null for a pièce deposited.
     * Part of the answer key: no student screen shows it.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $generation = null;

    /**
     * The entry proposed from the read data (PieceProposal::toArray(): journal, date, document,
     * label, lines, and the read data it was made from), null until there were read data to
     * propose from. Changing the read data never rewrites it: « Reprendre l'écriture depuis les
     * données lues » does (design-ux.md § 3.5).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $proposal = null;

    /** The entry its posting wrote in the brouillard; null until posted, or once that draft was deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Entry $entry = null;

    /**
     * What the student reported on it (PieceAnomaly::toArray()), oldest first.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $anomalies = [];

    /**
     * The errors the teacher slipped in (step 2.2b, AnswerKey::toArray()): the right read data and
     * the right proposal - third parties by their code, never an id, so that a copy of the dossier
     * keeps them true - and each error, its place and its wrong value. The read data and the
     * proposal above are the wrong ones, what the student receives. Null while it carries none.
     * Part of the answer key: no student screen shows it.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answerKey = null;

    /**
     * The pièce this one is the second arrival of (a « doublon », step 2.2b); null for a first
     * arrival. Part of the answer key: no student screen shows it.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Document $duplicateOf = null;

    public function __construct(Dossier $dossier, DocumentType $type, string $fileKey, string $fileName, string $mediaType, \DateTimeImmutable $receivedOn, ?User $depositedBy)
    {
        $this->dossier = $dossier;
        $this->type = $type;
        $this->fileKey = $fileKey;
        $this->fileName = $fileName;
        $this->mediaType = $mediaType;
        $this->receivedOn = $receivedOn;
        $this->depositedBy = $depositedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function getState(): DocumentState
    {
        return $this->state;
    }

    public function getFileKey(): string
    {
        return $this->fileKey;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function isPdf(): bool
    {
        return 'application/pdf' === $this->mediaType;
    }

    public function getReceivedOn(): \DateTimeImmutable
    {
        return $this->receivedOn;
    }

    /** Documents::scheduleArrival() alone moves it, on a template's open pièce. */
    public function arriveOn(\DateTimeImmutable $on): void
    {
        $this->receivedOn = $on;
    }

    public function getDepositedBy(): ?User
    {
        return $this->depositedBy;
    }

    public function getThirdParty(): ?ThirdParty
    {
        return $this->thirdParty;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getDocumentDate(): ?\DateTimeImmutable
    {
        return $this->documentDate;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getOrderReference(): ?string
    {
        return $this->orderReference;
    }

    /** @return list<VatLine> */
    public function getVatLines(): array
    {
        return array_map(VatLine::fromArray(...), $this->vatLines);
    }

    public function getTotalExcludingTax(): ?Money
    {
        return $this->totalExcludingTax;
    }

    public function getTotalVat(): ?Money
    {
        return $this->totalVat;
    }

    public function getTotalIncludingTax(): ?Money
    {
        return $this->totalIncludingTax;
    }

    public function isGenerated(): bool
    {
        return null !== $this->generation;
    }

    /** @return array<string, mixed>|null */
    public function getGeneration(): ?array
    {
        return $this->generation;
    }

    /** @param array<string, mixed> $generation */
    public function generatedFrom(array $generation): void
    {
        $this->generation = $generation;
    }

    /**
     * The read data replaced whole (Documents::saveReading(), after DocumentReadRules).
     *
     * @param array<array-key, VatLine> $vatLines
     */
    public function read(DocumentType $type, ?ThirdParty $thirdParty, ?string $number, ?\DateTimeImmutable $documentDate, ?\DateTimeImmutable $dueDate, ?string $orderReference, array $vatLines, ?Money $totalExcludingTax, ?Money $totalVat, ?Money $totalIncludingTax): void
    {
        $this->type = $type;
        $this->thirdParty = $thirdParty;
        $this->number = $number;
        $this->documentDate = $documentDate;
        $this->dueDate = $dueDate;
        $this->orderReference = $orderReference;
        $this->vatLines = array_map(static fn (VatLine $line): array => $line->toArray(), array_values($vatLines));
        $this->totalExcludingTax = $totalExcludingTax;
        $this->totalVat = $totalVat;
        $this->totalIncludingTax = $totalIncludingTax;
    }

    /** @return array<string, mixed>|null */
    public function getProposal(): ?array
    {
        return $this->proposal;
    }

    /** @param array<string, mixed> $proposal */
    public function propose(array $proposal): void
    {
        $this->proposal = $proposal;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    /** Its entry is in the brouillard: the pièce is posted and its read data no longer change. */
    public function posted(Entry $entry): void
    {
        if (!$this->state->isOpen()) {
            throw new \LogicException('Only an open pièce is posted.');
        }
        $this->entry = $entry;
        $this->state = DocumentState::Posted;
    }

    /** Worked on (its read data saved, its entry taken again, a reserve made): « En cours ». */
    public function startProcessing(): void
    {
        if (DocumentState::ToProcess === $this->state) {
            $this->state = DocumentState::InProgress;
        }
    }

    /** @return list<PieceAnomaly> */
    public function getAnomalies(): array
    {
        return array_map(PieceAnomaly::fromArray(...), $this->anomalies);
    }

    /** A rejected pièce is set aside (« Écartée »); one posted with a reserve stays open. */
    public function reportAnomaly(PieceAnomaly $anomaly): void
    {
        if (!$this->state->isOpen()) {
            throw new \LogicException('Only an open pièce takes an anomaly.');
        }
        $this->anomalies[] = $anomaly->toArray();
        if (AnomalyOutcome::Reject === $anomaly->outcome) {
            $this->state = DocumentState::Discarded;
        } else {
            $this->startProcessing();
        }
    }

    /** The reason it was rejected: the comment of the anomaly that rejected it. */
    public function getRejection(): ?PieceAnomaly
    {
        if (DocumentState::Discarded !== $this->state) {
            return null;
        }
        foreach (array_reverse($this->getAnomalies()) as $anomaly) {
            if (AnomalyOutcome::Reject === $anomaly->outcome) {
                return $anomaly;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function getAnswerKey(): ?array
    {
        return $this->answerKey;
    }

    /** @param array<string, mixed>|null $answerKey null once it carries no error */
    public function keepAnswerKey(?array $answerKey): void
    {
        $this->answerKey = $answerKey;
    }

    public function getDuplicateOf(): ?self
    {
        return $this->duplicateOf;
    }

    /** It arrives a second time: the first arrival it duplicates. */
    public function markDuplicateOf(self $first): void
    {
        if ($first === $this || null !== $first->duplicateOf) {
            throw new \LogicException('A pièce duplicates a first arrival, never itself nor a duplicate.');
        }
        $this->duplicateOf = $first;
    }

    /** An anomaly was reported with « Comptabiliser avec réserve »: its controls no longer stop it. */
    public function isPostedWithReserve(): bool
    {
        foreach ($this->getAnomalies() as $anomaly) {
            if (AnomalyOutcome::PostWithReserve === $anomaly->outcome) {
                return true;
            }
        }

        return false;
    }
}
