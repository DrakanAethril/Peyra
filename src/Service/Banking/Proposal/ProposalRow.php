<?php

declare(strict_types=1);

namespace App\Service\Banking\Proposal;

use App\Entity\Banking\PaymentApproval;
use App\Entity\ThirdParty\ThirdParty;
use App\Service\Banking\Payment\OpenInvoice;

/** A supplier's invoice still to pay in the payment proposal (step 2.7), with its « bon à payer » when given. */
final readonly class ProposalRow
{
    public function __construct(
        public ThirdParty $supplier,
        public OpenInvoice $invoice,
        public ?PaymentApproval $approval,
    ) {
    }

    public function id(): int
    {
        return (int) $this->invoice->entry->getId();
    }

    public function isApproved(): bool
    {
        return null !== $this->approval;
    }
}
