<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Service\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * The part of a payment that settles one invoice (step 2.6b) - the invoice named by its entry, which
 * keeps its id while a draft is rewritten -, and the VAT on receipts it moved from 445800 to 445710
 * (zero for an invoice under VAT on debits): the next payment of the same invoice reads both.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_allocation')]
class PaymentAllocation implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'allocations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $invoice;

    #[ORM\Column(type: 'money')]
    private Money $amount;

    #[ORM\Column(type: 'money')]
    private Money $vatTransferred;

    public function __construct(Payment $payment, Entry $invoice, Money $amount, Money $vatTransferred)
    {
        $this->dossier = $payment->getDossier();
        $this->payment = $payment;
        $this->invoice = $invoice;
        $this->amount = $amount;
        $this->vatTransferred = $vatTransferred;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function getInvoice(): Entry
    {
        return $this->invoice;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }

    public function getVatTransferred(): Money
    {
        return $this->vatTransferred;
    }
}
