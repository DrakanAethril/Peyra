<?php

declare(strict_types=1);

namespace App\Service\Banking\Proposal;

use App\Entity\ThirdParty\ThirdParty;
use App\Service\Shared\Money;

/**
 * The transfer order a payment proposal prepares (step 2.7; design-ux.md § 3.2 « ordre de virement en
 * aperçu »): one transfer per supplier, the sum of its invoices chosen. `faults` stop the generation
 * (an invoice no longer to pay or not approved; a double payment at G and B), `warnings` do not (a
 * double payment at A). Both are sentences, keyed by supplier code (or `invoices`).
 */
final readonly class TransferOrder
{
    /**
     * @param list<array{supplier: ThirdParty, rows: list<ProposalRow>, amount: Money}> $transfers in supplier code order
     * @param array<string, string>                                                     $faults
     * @param array<string, string>                                                     $warnings
     */
    public function __construct(
        public array $transfers,
        public array $faults,
        public array $warnings,
    ) {
    }

    public function total(): Money
    {
        return Money::sum(array_map(static fn (array $transfer): Money => $transfer['amount'], $this->transfers));
    }

    public function isEmpty(): bool
    {
        return [] === $this->transfers;
    }

    /** @return list<int> the invoices' entry ids, in the order of the order */
    public function invoiceIds(): array
    {
        $ids = [];
        foreach ($this->transfers as $transfer) {
            foreach ($transfer['rows'] as $row) {
                $ids[] = $row->id();
            }
        }

        return $ids;
    }
}
