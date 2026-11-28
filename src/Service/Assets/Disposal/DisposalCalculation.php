<?php

declare(strict_types=1);

namespace App\Service\Assets\Disposal;

use App\Service\Shared\Money;

/**
 * What a fixed asset's way out comes to (step 3.4b; design-ux.md § 3.2 « Cessions »: « calcul détaillé
 * affiché »), pure over amounts the plan and the fiche give:
 *
 * - the depreciation up to the day of the way out, already in the books (`before`) and the dotation
 *   that completes it (`complement`): their sum is what is written back on 28;
 * - the net book value: the cost less that depreciation and the impairment standing (reversed to 78);
 * - the result: the price less the net book value - a gain when positive, a loss when negative;
 * - the dérogatoire still standing is reversed (787), the VAT given back (VatRegularisation) is a charge.
 */
final readonly class DisposalCalculation
{
    public function __construct(
        public Money $cost,
        public Money $before,
        public Money $complement,
        public Money $impairment,
        public Money $derogatory,
        public Money $price,
        public Money $vat,
        public Money $vatRegularisation,
    ) {
    }

    /** The depreciation written back: before the exercise's dotations, and up to the day of the way out. */
    public function depreciation(): Money
    {
        return $this->before->plus($this->complement);
    }

    /** What leaves the asset account at its cost, less the depreciation: on 657 (675). */
    public function bookValueOut(): Money
    {
        return $this->cost->minus($this->depreciation());
    }

    /** The net book value: also net of the impairment. */
    public function netBookValue(): Money
    {
        return $this->bookValueOut()->minus($this->impairment);
    }

    /** The gain (positive) or the loss (negative) on the way out, before the VAT given back. */
    public function result(): Money
    {
        return $this->price->minus($this->netBookValue());
    }

    /** What the buyer owes: the price and its VAT. */
    public function receivable(): Money
    {
        return $this->price->plus($this->vat);
    }
}
