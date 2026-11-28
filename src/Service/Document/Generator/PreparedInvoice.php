<?php

declare(strict_types=1);

namespace App\Service\Document\Generator;

use App\Entity\ThirdParty\ThirdParty;

/** An invoice ready to print (step 2.1b): the operation, its supplier, what it prints, its look. */
final readonly class PreparedInvoice
{
    public function __construct(
        public InvoiceOperation $operation,
        public ThirdParty $supplier,
        public PrintedInvoice $printed,
        public InvoiceCharter $charter,
    ) {
    }

    /** « F26-0042.pdf », or the supplier's name when the number is left out. */
    public function fileName(): string
    {
        $name = $this->printed->number ?? 'Facture '.$this->supplier->getName();

        return trim(str_replace(['/', '\\'], '-', $name)).'.pdf';
    }
}
