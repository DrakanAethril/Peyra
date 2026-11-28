<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use Brick\Math\BigDecimal;

/**
 * Whether an item applies, by the establishment's headcount (« effectif ») - the thresholds the
 * contributions know: 11 (vocational training, mobility, forfait social), 20 (the employer's flat
 * deduction on overtime), 50 (FNAL, general reduction).
 */
enum HeadcountCondition: string
{
    case All = 'all';
    case Below11 = 'below_11';
    case From11 = 'from_11';
    case Below20 = 'below_20';
    case From20 = 'from_20';
    case Below50 = 'below_50';
    case From50 = 'from_50';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Tout effectif',
            self::Below11 => 'Moins de 11 salariés',
            self::From11 => '11 salariés et plus',
            self::Below20 => 'Moins de 20 salariés',
            self::From20 => '20 salariés et plus',
            self::Below50 => 'Moins de 50 salariés',
            self::From50 => '50 salariés et plus',
        };
    }

    public function holds(BigDecimal $headcount): bool
    {
        return match ($this) {
            self::All => true,
            self::Below11 => $headcount->isLessThan(11),
            self::From11 => $headcount->isGreaterThanOrEqualTo(11),
            self::Below20 => $headcount->isLessThan(20),
            self::From20 => $headcount->isGreaterThanOrEqualTo(20),
            self::Below50 => $headcount->isLessThan(50),
            self::From50 => $headcount->isGreaterThanOrEqualTo(50),
        };
    }
}
