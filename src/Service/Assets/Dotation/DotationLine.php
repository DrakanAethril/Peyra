<?php

declare(strict_types=1);

namespace App\Service\Assets\Dotation;

use App\Service\Shared\Money;

/**
 * One fixed asset in the dotations of a period (step 3.4b; design-ux.md § 3.2 « Dotations »): its
 * dotation since the last one passed, its dérogatoire (positive a dotation, negative a reprise) when
 * it has a fiscal plan, the movement of its impairment at the end of the exercise (positive a dotation,
 * negative a reprise), and the accounts they go to.
 */
final readonly class DotationLine
{
    public function __construct(
        public string $code,
        public string $label,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $through,
        public Money $dotation,
        public string $dotationAccount,
        public ?string $depreciationAccount,
        public ?Money $derogatory = null,
        public ?Money $impairment = null,
        public string $impairmentAccount = '',
        public string $impairmentDotationAccount = '',
        public string $impairmentRepriseAccount = '',
    ) {
    }

    /** Nothing to write for this asset. */
    public function isEmpty(): bool
    {
        return $this->dotation->isZero() && (null === $this->derogatory || $this->derogatory->isZero()) && (null === $this->impairment || $this->impairment->isZero());
    }
}
