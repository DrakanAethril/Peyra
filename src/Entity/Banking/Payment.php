<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Accounting\Entry;
use App\Entity\Accounting\Journal;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\ThirdParty\ThirdParty;
use App\Enum\Banking\BillRemittance;
use App\Enum\Banking\PaymentDirection;
use App\Enum\Banking\PaymentState;
use App\Enum\ThirdParty\PaymentMethod;
use App\Repository\Banking\PaymentRepository;
use App\Service\Shared\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A payment (« règlement », step 2.6b): money received from a customer or paid to a supplier on a
 * day, by a method, into or out of a journal - a bank or a cash journal, a general journal for a
 * bill drawn on a customer -, under a reference (the cheque's number, the transfer's, the bill's).
 * It names the invoices it settles (PaymentAllocation) and asks the books for its entry through
 * AccountingGateway (`entry`, null under « Laisser saisir »). A cheque received waits on 511200
 * until a ChequeDeposit takes it to the bank; a bill waits on 413000 until it is remitted for
 * collection or for discount (`remittance`, its own entry). Written by Payments alone.
 */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payment')]
class Payment implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: PaymentDirection::class)]
    private PaymentDirection $direction;

    #[ORM\Column(length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ThirdParty $thirdParty;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Journal $journal;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $paidOn;

    #[ORM\Column(length: 40)]
    private string $reference;

    #[ORM\Column(type: 'money')]
    private Money $amount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Entry $entry = null;

    /** @var Collection<int, PaymentAllocation> */
    #[ORM\OneToMany(targetEntity: PaymentAllocation::class, mappedBy: 'payment')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $allocations;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ChequeDeposit $deposit = null;

    /** A bill's due date. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueOn;

    #[ORM\Column(length: 16, nullable: true, enumType: BillRemittance::class)]
    private ?BillRemittance $remittance = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remittedOn = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Journal $remittanceJournal = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Entry $remittanceEntry = null;

    public function __construct(Dossier $dossier, PaymentDirection $direction, PaymentMethod $method, ThirdParty $thirdParty, Journal $journal, \DateTimeImmutable $paidOn, string $reference, Money $amount, ?\DateTimeImmutable $dueOn)
    {
        $this->dossier = $dossier;
        $this->direction = $direction;
        $this->method = $method;
        $this->thirdParty = $thirdParty;
        $this->journal = $journal;
        $this->paidOn = $paidOn;
        $this->reference = $reference;
        $this->amount = $amount;
        $this->dueOn = $dueOn;
        $this->allocations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDirection(): PaymentDirection
    {
        return $this->direction;
    }

    public function getMethod(): PaymentMethod
    {
        return $this->method;
    }

    public function getThirdParty(): ThirdParty
    {
        return $this->thirdParty;
    }

    public function getJournal(): Journal
    {
        return $this->journal;
    }

    public function getPaidOn(): \DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getEntry(): ?Entry
    {
        return $this->entry;
    }

    /** @return list<PaymentAllocation> */
    public function getAllocations(): array
    {
        return array_values($this->allocations->toArray());
    }

    public function getDeposit(): ?ChequeDeposit
    {
        return $this->deposit;
    }

    public function getDueOn(): ?\DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function getRemittance(): ?BillRemittance
    {
        return $this->remittance;
    }

    public function getRemittedOn(): ?\DateTimeImmutable
    {
        return $this->remittedOn;
    }

    public function getRemittanceJournal(): ?Journal
    {
        return $this->remittanceJournal;
    }

    public function getRemittanceEntry(): ?Entry
    {
        return $this->remittanceEntry;
    }

    public function isChequeReceived(): bool
    {
        return PaymentDirection::Receipt === $this->direction && PaymentMethod::Cheque === $this->method;
    }

    public function isBill(): bool
    {
        return PaymentMethod::Bill === $this->method;
    }

    public function state(): PaymentState
    {
        if ($this->isChequeReceived()) {
            return null === $this->deposit ? PaymentState::ToDeposit : PaymentState::Deposited;
        }
        if ($this->isBill()) {
            return match ($this->remittance) {
                null => PaymentState::InPortfolio,
                BillRemittance::Collection => PaymentState::AtCollection,
                BillRemittance::Discount => PaymentState::Discounted,
            };
        }

        return PaymentState::Recorded;
    }

    public function linkEntry(Entry $entry): void
    {
        $this->entry = $entry;
    }

    public function depositedIn(ChequeDeposit $deposit): void
    {
        $this->deposit = $deposit;
    }

    public function remit(BillRemittance $remittance, Journal $journal, \DateTimeImmutable $on, ?Entry $entry): void
    {
        $this->remittance = $remittance;
        $this->remittanceJournal = $journal;
        $this->remittedOn = $on;
        $this->remittanceEntry = $entry;
    }
}
