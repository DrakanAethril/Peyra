<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Enum\Document\DocumentType;
use App\Service\Shared\Money;

/** A pièce's read data once DocumentReadRules accepted them: what Documents writes. */
final readonly class DocumentFacts
{
    /** @param list<VatLine> $vatLines */
    public function __construct(
        public DocumentType $type,
        public ?int $thirdPartyId,
        public ?string $number,
        public ?\DateTimeImmutable $date,
        public ?\DateTimeImmutable $dueDate,
        public ?string $orderReference,
        public array $vatLines,
        public ?Money $totalExcludingTax,
        public ?Money $totalVat,
        public ?Money $totalIncludingTax,
    ) {
    }
}
