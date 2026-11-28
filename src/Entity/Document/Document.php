<?php

declare(strict_types=1);

namespace App\Entity\Document;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\Identity\User;
use App\Entity\Shared\DossierFileKey;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Document\DocumentState;
use App\Enum\Document\DocumentType;
use App\Repository\Document\DocumentRepository;
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
 * Its entries are not a column: the dossier's entries carrying its number as their pièce reference
 * are read (DocumentRepository's caller, the sheet).
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
}
