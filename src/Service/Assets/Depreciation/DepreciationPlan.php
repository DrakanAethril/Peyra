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
 * - After an **impairment** (« dépréciation », its balance at the end of an exercise), the
 *   accounting annuities spread what is left to depreciate, net of it, over the time left (PCG
 *   art. 214-17): the days left for the linear method, the units left for the units of work, the net
 *   value less the impairment for the declining one. The fiscal plan does not move.
 */
final class DepreciationPlan
{
    /** @return list<PlanRow> */
    public static function rows(PlanInput $input): array
    {
        $base = $input->base();
        $annuities = match ($input->method) {
            DepreciationMethod::None => [],
            DepreciationMethod::Linear => self::linear($base, $input->inServiceOn, (int) $input->years, $input->windows, $input->disposedOn, $input->impairments),
            DepreciationMethod::Declining => self::declining($base, $input->acquiredOn, (int) $input->years, $input->coefficient ?? BigDecimal::one(), $input->windows, $input->disposedOn, $input->impairments),
            DepreciationMethod::Units => self::units($base, $input->inServiceOn, (int) $input->totalUnits, $input->units, $input->windows, $input->disposedOn, $input->impairments),
        };
        $fiscal = DepreciationMethod::Linear === $input->method && $input->fiscalDeclining && null !== $input->coefficient
            ? self::declining($base, $input->acquiredOn, (int) $input->years, $input->coefficient, $input->windows, $input->disposedOn, [])
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
            $impairment = self::impairmentAt($input->impairments, $input->windows[$index]['end']);
            $rows[] = new PlanRow(
                $input->windows[$index]['start'],
                $input->windows[$index]['end'],
                $annuity['counted'],
                $opening,
                $annuity['amount'],
                $cumul,
                $input->cost->minus($cumul)->minus($impairment ?? Money::zero()),
                $annuity['switched'],
                $fiscalAmount,
                $derogatory,
                null === $fiscal ? null : $balance,
                $impairment,
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
     * @param array<string, Money>                                             $impairments
     *
     * @return array<int, array{counted: int, amount: Money, switched: bool}>
     */
    private static function linear(Money $base, \DateTimeImmutable $from, int $years, array $windows, ?\DateTimeImmutable $disposedOn, array $impairments): array
    {
        $end = $from->modify('+'.$years.' years')->modify('-1 day');
        $stop = null !== $disposedOn && $disposedOn < $end ? $disposedOn : $end;
        $done = Money::zero();
        $annuities = [];
        foreach ($windows as $index => $window) {
            if ($window['end'] < $from || $window['start'] > $stop) {
                continue;
            }
            $first = max($window['start'], $from);
            $days = DayCount::days($first, min($window['end'], $stop));
            $impaired = self::impairmentAt($impairments, $first->modify('-1 day'));
            $left = $base->minus($done)->minus($impaired ?? Money::zero());
            if ($left->isNegative()) {
                $left = Money::zero();
            }
            $amount = match (true) {
                $window['end'] >= $end => $left,
                null !== $impaired => self::share($left, BigRational::of($days)->dividedBy(max(1, DayCount::days($first, $end)))),
                default => self::share($base, BigRational::of($days)->dividedBy($years * 360)),
            };
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
     * @param array<string, Money>                                             $impairments
     *
     * @return array<int, array{counted: int, amount: Money, switched: bool}>
     */
    private static function declining(Money $base, \DateTimeImmutable $acquiredOn, int $years, BigDecimal $coefficient, array $windows, ?\DateTimeImmutable $disposedOn, array $impairments): array
    {
        $from = $acquiredOn->modify('first day of this month');
        $end = $from->modify('+'.$years.' years')->modify('-1 day');
        $stop = null !== $disposedOn && $disposedOn < $end ? $disposedOn : $end;
        $rate = BigRational::of($coefficient)->dividedBy($years);
        $total = $years * 12;
        $elapsed = 0;
        $net = $base;
        $switch = null;
        $seen = null;
        $annuities = [];
        foreach ($windows as $index => $window) {
            if ($window['end'] < $from || $window['start'] > $stop) {
                continue;
            }
            $first = max($window['start'], $from);
            $months = DayCount::months($first, min($window['end'], $stop));
            $remaining = $total - $elapsed;
            $impaired = self::impairmentAt($impairments, $first->modify('-1 day'));
            $basis = $net->minus($impaired ?? Money::zero());
            if ($basis->isNegative()) {
                $basis = Money::zero();
            }
            if (null !== $impaired && $impaired !== $seen) {
                // A new impairment: what is left is spread anew from here.
                $seen = $impaired;
                $switch = null === $switch ? null : [$basis, $remaining];
            }
            if ($window['end'] >= $end) {
                $amount = $basis;
            } else {
                // The linear rate over the months left, against the declining rate: 12 / remaining ≥ rate.
                if (null === $switch && BigRational::of(12)->dividedBy($remaining)->compareTo($rate) >= 0) {
                    $switch = [$basis, $remaining];
                }
                $amount = null === $switch
                    ? self::share($basis, $rate->multipliedBy($months)->dividedBy(12))
                    : self::share($switch[0], BigRational::of($months)->dividedBy($switch[1]));
                if ($amount->compareTo($basis) > 0) {
                    $amount = $basis;
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
     * @param array<string, Money>                                             $impairments
     *
     * @return array<int, array{counted: int, amount: Money, switched: bool}>
     */
    private static function units(Money $base, \DateTimeImmutable $from, int $total, array $units, array $windows, ?\DateTimeImmutable $disposedOn, array $impairments): array
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
            $impaired = self::impairmentAt($impairments, max($window['start'], $from)->modify('-1 day'));
            $left = $base->minus($done)->minus($impaired ?? Money::zero());
            if ($left->isNegative()) {
                $left = Money::zero();
            }
            $amount = match (true) {
                $consumed + $used >= $total => $left,
                null !== $impaired => self::share($left, BigRational::of($used)->dividedBy($total - $consumed)),
                default => self::share($base, BigRational::of($used)->dividedBy($total)),
            };
            if ($amount->compareTo($left) > 0) {
                $amount = $left;
            }
            $consumed += $used;
            $annuities[$index] = ['counted' => $used, 'amount' => $amount, 'switched' => false];
            $done = $done->plus($amount);
        }

        return $annuities;
    }

    /**
     * The impairment's balance at the end of that day: the last one given on or before it; null when
     * none was.
     *
     * @param array<string, Money> $impairments by the last day of a window (Y-m-d)
     */
    public static function impairmentAt(array $impairments, \DateTimeImmutable $day): ?Money
    {
        $found = null;
        $key = $day->format('Y-m-d');
        ksort($impairments, \SORT_STRING);
        foreach ($impairments as $at => $balance) {
            if ((string) $at <= $key) {
                $found = $balance;
            }
        }

        return $found;
    }

    /** The part of an amount, rounded to the cent half up. */
    private static function share(Money $amount, BigRational $part): Money
    {
        return Money::cents(BigRational::of($amount->cents)->multipliedBy($part)->toScale(0, RoundingMode::HalfUp)->toInt());
    }
}
