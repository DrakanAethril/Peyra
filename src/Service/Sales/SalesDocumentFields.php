<?php

declare(strict_types=1);

namespace App\Service\Sales;

/** A commercial document SalesDocumentRules accepted: the customer by code, ready to write. */
final readonly class SalesDocumentFields
{
    /** @param list<SalesLine> $lines */
    public function __construct(
        public string $customer,
        public \DateTimeImmutable $date,
        public ?\DateTimeImmutable $dueDate,
        public ?string $reference,
        public array $lines,
        public ?string $cashDiscount = null,
        public bool $vatOnReceipts = false,
    ) {
    }
}
