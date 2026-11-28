<?php

declare(strict_types=1);

namespace App\Entity\Banking;

use App\Entity\Accounting\Entry;
use App\Entity\Dossier\Dossier;
use App\Entity\Dossier\DossierOwned;
use App\Entity\Dossier\DossierOwnedTrait;
use App\Entity\Identity\User;
use App\Repository\Banking\PaymentApprovalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The « bon à payer » of a supplier's invoice (step 2.7): the invoice named by its entry - which keeps
 * its id while a draft is rewritten, and takes the approval with it when deleted -, the dossier's date
 * it was given on and by whom. Only an approved invoice enters a payment proposal's transfer order.
 */
#[ORM\Entity(repositoryClass: PaymentApprovalRepository::class)]
#[ORM\Table(name: 'payment_approval')]
#[ORM\UniqueConstraint(name: 'uniq_payment_approval_invoice', columns: ['invoice_id'])]
class PaymentApproval implements DossierOwned
{
    use DossierOwnedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $invoice;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $approvedOn;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $approvedBy;

    public function __construct(Dossier $dossier, Entry $invoice, \DateTimeImmutable $approvedOn, ?User $approvedBy)
    {
        $this->dossier = $dossier;
        $this->invoice = $invoice;
        $this->approvedOn = $approvedOn;
        $this->approvedBy = $approvedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Entry
    {
        return $this->invoice;
    }

    public function getApprovedOn(): \DateTimeImmutable
    {
        return $this->approvedOn;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }
}
