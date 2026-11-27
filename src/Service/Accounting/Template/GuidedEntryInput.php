<?php

declare(strict_types=1);

namespace App\Service\Accounting\Template;

/** What a person filled in the guided entry: the date, the document, the third party, the amount (cents) and the rate as typed. */
final readonly class GuidedEntryInput
{
    public function __construct(
        public ?\DateTimeImmutable $date = null,
        public string $document = '',
        public string $thirdParty = '',
        public ?int $amount = null,
        public string $rate = '',
    ) {
    }
}
