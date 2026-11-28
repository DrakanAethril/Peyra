<?php

declare(strict_types=1);

namespace App\Service\Inventory\Stock;

/** A physical inventory that may not be validated, with every reason: nothing is written. */
final class StockCountRefused extends \RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }
}
