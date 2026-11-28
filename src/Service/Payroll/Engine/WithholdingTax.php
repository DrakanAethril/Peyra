<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Service\Regulatory\RegulatorySnapshot;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The withholding tax's neutral rate (lot 4, step 4.3c): when the tax office sent no rate for an
 * employee, the rate of the monthly grid (`social.withholding_tax.default_rate_grid`, mainland France,
 * BOI-BAREME-000037) in force on the payslip's day, read on the month's base - the net taxable. A
 * bracket includes its lower bound and excludes its upper one; the last has none.
 */
final class WithholdingTax
{
    public const string GRID_KEY = 'social.withholding_tax.default_rate_grid';

    /** @return array{rate: BigDecimal, from: BigDecimal, to: ?BigDecimal} the rate in percent and its bracket */
    public static function neutralRate(RegulatorySnapshot $regulatory, Money $base): array
    {
        $amount = $base->toDecimal();
        $tree = $regulatory->value(self::GRID_KEY)->getTree();
        $count = \is_array($tree) ? \count($tree) : 0;
        for ($i = 0; $i < $count; ++$i) {
            $from = $regulatory->decimal(self::GRID_KEY, $i, 'from');
            $last = $i === $count - 1;
            $to = $last ? null : $regulatory->decimal(self::GRID_KEY, $i, 'to');
            if ($amount->isGreaterThanOrEqualTo($from) && (null === $to || $amount->isLessThan($to))) {
                return ['rate' => $regulatory->decimal(self::GRID_KEY, $i, 'rate'), 'from' => $from, 'to' => $to];
            }
        }
        if ($count > 0 && $amount->isLessThan($regulatory->decimal(self::GRID_KEY, 0, 'from'))) {
            // A negative base (a month of regularisation): the first bracket's rate, nought.
            return ['rate' => $regulatory->decimal(self::GRID_KEY, 0, 'rate'), 'from' => $regulatory->decimal(self::GRID_KEY, 0, 'from'), 'to' => $regulatory->decimal(self::GRID_KEY, 0, 'to')];
        }

        throw new \LogicException(\sprintf('La grille du taux neutre (%s) ne couvre pas %s.', self::GRID_KEY, (string) $amount));
    }
}
