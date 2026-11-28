<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Enum\Document\InvoiceMention;
use App\Service\Document\VatLine;
use App\Service\Shared\Money;

/**
 * What a generated invoice prints (step 2.1b), faults included: its lines with their amounts, the
 * VAT by rate, the three totals, the number and the due date when their mention is printed, the
 * mentions printed, and whether it is addressed to another company. The pièce's read data are
 * these values: what the tool « read » is what the paper says.
 */
final readonly class PrintedInvoice
{
    /**
     * @param list<array{line: InvoiceLine, amount: Money}> $lines
     * @param list<VatLine>                                 $vatLines by rate, the highest first
     * @param list<InvoiceMention>                          $mentions
     */
    public function __construct(
        public array $lines,
        public array $vatLines,
        public Money $totalExcludingTax,
        public Money $totalVat,
        public Money $totalIncludingTax,
        public ?string $number,
        public \DateTimeImmutable $date,
        public ?\DateTimeImmutable $dueDate,
        public ?string $orderReference,
        public array $mentions,
        public bool $wrongRecipient,
    ) {
    }

    /** For the templates: `printed.shows('supplier_vat_number')`. */
    public function shows(string $mention): bool
    {
        return \in_array(InvoiceMention::tryFrom($mention), $this->mentions, true);
    }
}
