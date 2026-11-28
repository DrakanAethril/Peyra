<?php

declare(strict_types=1);

namespace App\Service\Assets\Statement;

use App\Service\Shared\Money;

/**
 * One line of the state of fixed assets and depreciation (step 3.4b): an asset, or the total of a group
 * of them - the gross value at the opening of the exercise, what entered and left, at its end; the same
 * for the depreciation (the dotations of the exercise, what a way out wrote back) and the impairment;
 * the net book value at the end.
 */
final readonly class StatementRow
{
    public function __construct(
        public string $code,
        public string $label,
        public string $account,
        public Money $grossOpening,
        public Money $grossIncrease,
        public Money $grossDecrease,
        public Money $depreciationOpening,
        public Money $dotations,
        public Money $depreciationDecrease,
        public Money $impairmentOpening,
        public Money $impairmentIncrease,
        public Money $impairmentDecrease,
    ) {
    }

    public function grossClosing(): Money
    {
        return $this->grossOpening->plus($this->grossIncrease)->minus($this->grossDecrease);
    }

    public function depreciationClosing(): Money
    {
        return $this->depreciationOpening->plus($this->dotations)->minus($this->depreciationDecrease);
    }

    public function impairmentClosing(): Money
    {
        return $this->impairmentOpening->plus($this->impairmentIncrease)->minus($this->impairmentDecrease);
    }

    public function netClosing(): Money
    {
        return $this->grossClosing()->minus($this->depreciationClosing())->minus($this->impairmentClosing());
    }

    /** @param list<self> $rows */
    public static function total(string $label, array $rows): self
    {
        $sum = static fn (string $field): Money => Money::sum(array_map(static fn (self $row): Money => $row->{$field}, $rows));

        return new self('', $label, '', $sum('grossOpening'), $sum('grossIncrease'), $sum('grossDecrease'), $sum('depreciationOpening'), $sum('dotations'), $sum('depreciationDecrease'), $sum('impairmentOpening'), $sum('impairmentIncrease'), $sum('impairmentDecrease'));
    }
}
