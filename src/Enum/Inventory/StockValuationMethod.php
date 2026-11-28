<?php

declare(strict_types=1);

namespace App\Enum\Inventory;

/**
 * How an article's exits are valued (lot 2, step 2.5), chosen per article: the weighted average
 * cost recomputed after each entry (« CMUP après chaque entrée »), the weighted average cost of
 * the month (« CMUP de fin de période »: every exit of a month at the average of its opening
 * stock and of its entries), or first in, first out (« PEPS »). StockValuation applies them.
 */
enum StockValuationMethod: string
{
    case WeightedAverage = 'weighted_average';
    case PeriodicAverage = 'periodic_average';
    case Fifo = 'fifo';

    public function label(): string
    {
        return match ($this) {
            self::WeightedAverage => 'CMUP après chaque entrée',
            self::PeriodicAverage => 'CMUP de fin de période (mois)',
            self::Fifo => 'PEPS (premier entré, premier sorti)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::WeightedAverage => 'CMUP',
            self::PeriodicAverage => 'CMUP du mois',
            self::Fifo => 'PEPS',
        };
    }
}
