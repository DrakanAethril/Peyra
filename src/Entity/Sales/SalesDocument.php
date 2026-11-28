<?php

declare(strict_types=1);

namespace App\Entity\Sales;

use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Sales\SalesDocumentState;
use App\Enum\Sales\SalesDocumentType;
use App\Repository\Sales\SalesDocumentRepository;
use App\Service\Sales\SalesComputation;
use App\Service\Sales\SalesLine;
use App\Service\Sales\SalesTotals;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A commercial document of the sales chain (lot 2, step 2.3): a quote, an order, a delivery note,
 * an invoice or a credit note - one entity, its type says which. A draft is changed at will; once
 * validated it carries the next number of its type and **never changes again** (the entity refuses
 * it): an invoice is corrected by a credit note. A document made from another names it (`source`):
 * that is the chain drawn at the head of its fiche. Its figures are read from its lines
 * (SalesComputation), never stored. The history says what happened to it, at the dossier's date -
 * « envoyé » included: nothing leaves the application. SalesDocuments is the only writer.
 *
 * @phpstan-type HistoryRow array{event: string, on: string, by: string, detail: string}
 * @phpstan-type CustomerPrint array{name: string, lines: list<string>, siren: ?string, vatNumber: ?string}
 */
#[ORM\Entity(repositoryClass: SalesDocumentRepository::class)]
#[ORM\Table(name: 'sales_document')]
#[ORM\UniqueConstraint(name: 'uniq_sales_document_number', columns: ['dossier_id', 'type', 'number'])]
#[ORM\Index(name: 'idx_sales_document_type', columns: ['dossier_id', 'type', 'state'])]
class SalesDocument implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: SalesDocumentType::class)]
    private SalesDocumentType $type;

    #[ORM\Column(length: 12, enumType: SalesDocumentState::class)]
    private SalesDocumentState $state = SalesDocumentState::Draft;

    /** « FV-2026-0001 », given at the validation. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $number = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ThirdParty $customer;

    /**
     * The customer as printed, frozen at the validation: a later change of their fiche does not
     * rewrite a validated document.
     *
     * @var CustomerPrint|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $customerPrint = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** An invoice's due date: the customer's terms read at its date, unless typed. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    /** The customer's reference or the object (« Chantier Rambaud »). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reference = null;

    /** @var list<array<mixed>> SalesLine::toArray() (`lines` is a MariaDB keyword) */
    #[ORM\Column(name: 'document_lines', type: Types::JSON)]
    private array $lines = [];

    /** The document it was made from: a quote for an order, an invoice for a credit note. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    private ?self $source = null;

    /** The dossier's date it was last sent on. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentOn = null;

    /** @var list<HistoryRow> */
    #[ORM\Column(type: Types::JSON)]
    private array $history = [];

    public function __construct(Dossier $dossier, SalesDocumentType $type, ThirdParty $customer, \DateTimeImmutable $date, ?self $source = null)
    {
        $this->dossier = $dossier;
        $this->type = $type;
        $this->customer = $customer;
        $this->date = $date;
        $this->source = $source;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): SalesDocumentType
    {
        return $this->type;
    }

    public function getState(): SalesDocumentState
    {
        return $this->state;
    }

    public function isDraft(): bool
    {
        return SalesDocumentState::Draft === $this->state;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    /** The number, or « Brouillon » with its type. */
    public function getTitle(): string
    {
        return $this->number ?? $this->type->label().' (brouillon)';
    }

    public function getCustomer(): ThirdParty
    {
        return $this->customer;
    }

    /** @return CustomerPrint|null */
    public function getCustomerPrint(): ?array
    {
        return $this->customerPrint;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    /** @return list<SalesLine> */
    public function getLines(): array
    {
        return array_map(SalesLine::fromArray(...), $this->lines);
    }

    public function getTotals(): SalesTotals
    {
        return SalesComputation::of($this->getLines());
    }

    public function getSource(): ?self
    {
        return $this->source;
    }

    public function getSentOn(): ?\DateTimeImmutable
    {
        return $this->sentOn;
    }

    /** @return list<HistoryRow> oldest first */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * SalesDocuments' write of a draft, every field at once.
     *
     * @param list<SalesLine> $lines
     */
    public function rewrite(ThirdParty $customer, \DateTimeImmutable $date, ?\DateTimeImmutable $dueDate, ?string $reference, array $lines): void
    {
        $this->refuseUnlessDraft();
        $this->customer = $customer;
        $this->date = $date;
        $this->dueDate = $dueDate;
        $this->reference = $reference;
        $this->lines = array_map(static fn (SalesLine $line): array => $line->toArray(), $lines);
    }

    /**
     * Validated: numbered, its customer frozen as printed, its due date settled.
     *
     * @param CustomerPrint $customerPrint
     */
    public function markValidated(string $number, array $customerPrint, ?\DateTimeImmutable $dueDate): void
    {
        $this->refuseUnlessDraft();
        $this->state = SalesDocumentState::Validated;
        $this->number = $number;
        $this->customerPrint = $customerPrint;
        $this->dueDate = $dueDate;
    }

    public function markSent(\DateTimeImmutable $on): void
    {
        if ($this->isDraft()) {
            throw new \LogicException('A draft is not sent.');
        }
        $this->sentOn = $on;
    }

    /** One more line of history (SalesDocuments). */
    public function recordHistory(string $event, \DateTimeImmutable $on, string $by, string $detail): void
    {
        $this->history[] = ['event' => $event, 'on' => $on->format('Y-m-d'), 'by' => $by, 'detail' => $detail];
    }

    private function refuseUnlessDraft(): void
    {
        if (!$this->isDraft()) {
            throw new \LogicException('A validated commercial document never changes: an invoice is corrected by a credit note.');
        }
    }
}
