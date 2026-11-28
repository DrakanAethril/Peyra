<?php

declare(strict_types=1);

namespace App\Service\Assets\Depreciation;

use App\Enum\Assets\DepreciationMethod;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * A fixed asset's depreciation plan (lot 3, step 3.4) - a pure function over PlanInput, the one
 * place an annuity is computed. Every annuity is rounded to the cent, half up, on its own; the
 * last one is what is left, so that a plan always ends on the depreciable base.
 *
 * - **Linear**: the base × the days of the exercise from the day of putting into service, on a
 *   year of 360 days (DayCount), / the years of use; until the day of disposal, when there is one.
 * - **Declining** (« dégressif », art. 39 A CGI): from the first day of the month of acquisition,
 *   the net value at the start of the exercise × the linear rate × the fiscal coefficient × the
 *   months / 12; once the linear rate over the months left reaches the declining rate, the net
 *   value then is spread evenly over those months (« bascule en linéaire »).
 * - **Units of work**: the base × the units consumed in the exercise / the units the asset will
 *   give; the plan stops at the first exercise whose units are not known.
 * - Beside a linear plan, a **fiscal declining** one gives the dérogatoire: the fiscal annuity
 *   above the accounting one is a dotation, below it a reprise of what was set aside.
 */
final class DepreciationPlan
{
    /** @return list<PlanRow> */
    public static function rows(PlanInput $input): array
    {
        $base = $input->base();
        $annuities = match ($input->method) {
            DepreciationMethod::None => [],
            DepreciationMethod::Linear => self::linear($base, $input->inServiceOn, (int) $input->years, $input->windows, $input->disposedOn),
            DepreciationMethod::Declining => self::declining($base, $input->acquiredOn, (int) $input->years, $input->coefficient ?? BigDecimal::one(), $input->windows, $input->disposedOn),
            DepreciationMethod::Units => self::units($base, $input->inServiceOn, (int) $input->totalUnits, $input->units, $input->windows, $input->disposedOn),
        };
        $fiscal = DepreciationMethod::Linear === $input->method && $input->fiscalDeclining && null !== $input->coefficient
            ? self::declining($base, $input->acquiredOn, (int) $input->years, $input->coefficient, $input->windows, $input->disposedOn)
            : null;

        $indexes = array_keys($annuities + ($fiscal ?? []));
        sort($indexes);
        $rows = [];
        $cumul = Money::zero();
        $balance = Money::zero();
        foreach ($indexes as $index) {
            $annuity = $annuities[$index] ?? ['counted' => 0, 'amount' => Money::zero(), 'switched' => false];
            $opening = $cumul;
            $cumul = $cumul->plus($annuity['amount']);
            $derogatory = null;
            $fiscalAmount = null;
            if (null !== $fiscal) {
                $fiscalAmount = ($fiscal[$index] ?? null)['amount'] ?? Money::zero();
                $gap = $fiscalAmount->minus($annuity['amount']);
                $derogatory = $gap->isNegative() ? (0 < $gap->abs()->compareTo($balance) ? $balance : $gap->abs())->negated() : $gap;
                $balance = $balance->plus($derogatory);
            }
            $rows[] = new PlanRow(
                $input->windows[$index]['start'],
                $input->windows[$index]['end'],
                $annuity['counted'],
                $opening,
                $annuity['amount'],
                $cumul,
                $input->cost->minus($cumul),
                $annuity['switched'],
                $fiscalAmount,
                $derogatory,
                null === $fiscal ? null : $balance,
            );
        }

        return $rows;
    }

    /**
     * Several plans of one asset added up exercise by exercise (the components of an asset, each
     * depreciated over its own length): an exercise one plan no longer reaches keeps its end.
     *
     * @param list<list<PlanRow>> $plans
     *
     * @return list<PlanRow>
     */
    public static function combine(array $plans): array
    {
        $byStart = [];
        foreach ($plans as $plan) {
            foreach ($plan as $row) {
                $byStart[$row->start->format('Y-m-d')] = [$row->start, $row->end];
            }
        }
        ksort($byStart);
        $rows = [];
        foreach ($byStart as $key => [$start, $end]) {
            $opening = Money::zero();
            $dotation = Money::zero();
            $closing = Money::zero();
            $net = Money::zero();
            foreach ($plans as $plan) {
                $found = null;
                $last = null;
                foreach ($plan as $row) {
                    if ($row->start->format('Y-m-d') === $key) {
                        $found = $row;
                    }
                    if ($row->start->format('Y-m-d') < $key) {
                        $last = $row;
                    }
                }
                if (null !== $found) {
                    $opening = $opening->plus($found->opening);
                    $dotation = $dotation->plus($found->dotation);
                    $closing = $closing->plus($found->closing);
                    $net = $net->plus($found->netValue);
                } elseif (null !== $last) {
                    $opening = $opening->plus($last->closing);
                    $closing = $closing->plus($last->closing);
                    $net = $net->plus($last->netValue);
                }
            }
            $rows[] = new PlanRow($start, $end, 0, $opening, $dotation, $closing, $net);
        }

        return $rows;
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $windows
     *
     * @return array<int, array{counted: int, amount: Money, switched: bool}>
     */
    private static function linear(Money $base, \DateTimeImmutable $from, int $years, array $windows, ?\DateTimeImmutable $disposedOn): array
    {
        $end = $from->modify('+'.$years.' years')->modify('-1 day');
        $stop = null !== $disposedOn && $disposedOn < $end ? $disposedOn : $end;
        $done = Money::zero();
        $annuities = [];
        foreach ($windows as $index => $window) {
            if ($window['end'] < $from || $window['start'] > $stop) {
                continue;
            }
            $days = DayCount::days(max($window['start'], $from), min($window['end'], $stop));
            $left = $base->minus($done);
            $amount = $window['end'] >= $end ? $left : self::share($base, BigRational::of($days)->dividedBy($years * 360));
            if ($amount->compareTo($left) > 0) {
                $amount = $left;
            }
            $annuities[$index] = ['counted' => $days, 'amount' => $amount, 'switched' => false];
            $done = $done->plus($amount);
        }

        return $annuities;
    }

    /**
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $windows
     *
     * @return array<int, array{counted: int, amount: Money, switched: bool}>
     */
    private static function declining(Money $base, \DateTimeImmutable $acquiredOn, int $years, BigDecimal $coefficient, array $windows, ?\DateTimeImmutable $disposedOn): array
    {
        $from = $acquiredOn->modify('first day of this month');
        $end = $from->modify('+'.$years.' years')->modify('-1 day');
        $stop = null !== $disposedOn && $disposedOn < $end ? $disposedOn : $end;
        $rate = BigRational::of($coefficient)->dividedBy($years);
        $total = $years * 12;
        $elapsed = 0;
        $net = $base;
        $switch = null;
        $annuities = [];
        foreach ($windows as $index => $window) {
            if ($window['end'] < $from || $window['start'] > $stop) {
                continue;
            }
            $months = DayCount::months(max($window['start'], $from), min($window['end'], $stop));
            $remaining = $total - $elapsed;
            if ($window['end'] >= $end) {
                $amount = $net;
            } else {
                // The linear rate over the months left, against the declining rate: 12 / remaining ≥ rate.
                if (null === $switch && BigRational::of(12)->dividedBy($remaining)->compareTo($rate) >= 0) {
                    $switch = [$net, $remaining];
                }
                $amount = null === $switch
                    ? self::share($net, $rate->multipliedBy($months)->dividedBy(12))
                    : self::share($switch[0], BigRational::of($months)->dividedBy($switch[1]));
                if ($amount->compareTo($net) > 0) {
                    $amount = $net;
                }
            }
            $annuities[$index] = ['counted' => $months, 'amount' => $amount, 'switched' => null !== $switch];
            $elapsed += $months;
            $net = $net->minus($amount);
        }

        return $annuities;
    }

    /**
     * @param array<string, int>                                               $units
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $windows
     *
     * @return array<int, array{counted: int, amount: Money, switched: bool}>
     */
    private static function units(Money $base, \DateTimeImmutable $from, int $total, array $units, array $windows, ?\DateTimeImmutable $disposedOn): array
    {
        $consumed = 0;
        $done = Money::zero();
        $annuities = [];
        foreach ($windows as $index => $window) {
            if ($window['end'] < $from || $total <= 0) {
                continue;
            }
            if ((null !== $disposedOn && $window['start'] > $disposedOn) || $consumed >= $total) {
                break;
            }
            $used = $units[$window['end']->format('Y-m-d')] ?? null;
            if (null === $used) {
                break;
            }
            $consumed += $used;
            $left = $base->minus($done);
            $amount = $consumed >= $total ? $left : self::share($base, BigRational::of($used)->dividedBy($total));
            $annuities[$index] = ['counted' => $used, 'amount' => $amount, 'switched' => false];
            $done = $done->plus($amount);
        }

        return $annuities;
    }

    /** The part of an amount, rounded to the cent half up. */
    private static function share(Money $amount, BigRational $part): Money
    {
        return Money::cents(BigRational::of($amount->cents)->multipliedBy($part)->toScale(0, RoundingMode::HalfUp)->toInt());
    }
}
