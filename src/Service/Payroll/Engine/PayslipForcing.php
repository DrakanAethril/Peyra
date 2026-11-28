<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * The values forced by hand on one line of a payslip (step 4.4b): its base, a side's rate, or a side's
 * amount where the side has no rate. Amounts are given as the line prints them without their sign; the
 * rubrique gives the sign (an absence, a reduction, a deduction from the net come off). A side whose base
 * or rate is forced is calculated again, base × rate rounded to the cent; a forced amount is taken as is.
 */
final readonly class PayslipForcing
{
    public const array FIELDS = ['base', 'employeeRate', 'employeeAmount', 'employerRate', 'employerAmount'];

    public function __construct(
        public ?BigDecimal $base = null,
        public ?BigDecimal $employeeRate = null,
        public ?Money $employeeAmount = null,
        public ?BigDecimal $employerRate = null,
        public ?Money $employerAmount = null,
    ) {
        foreach ([$base, $employeeRate, $employerRate, $employeeAmount?->toDecimal(), $employerAmount?->toDecimal()] as $value) {
            if (null !== $value && $value->isNegative()) {
                throw new \InvalidArgumentException('A forced value is written without its sign.');
            }
        }
    }

    public function isEmpty(): bool
    {
        return null === $this->base && null === $this->employeeRate && null === $this->employeeAmount && null === $this->employerRate && null === $this->employerAmount;
    }

    /** @return list<string> the fields forced, among FIELDS */
    public function fields(): array
    {
        return array_keys(array_filter($this->values(), static fn (BigDecimal|Money|null $value): bool => null !== $value));
    }

    /** @return array{base: ?BigDecimal, employeeRate: ?BigDecimal, employeeAmount: ?Money, employerRate: ?BigDecimal, employerAmount: ?Money} */
    private function values(): array
    {
        return ['base' => $this->base, 'employeeRate' => $this->employeeRate, 'employeeAmount' => $this->employeeAmount, 'employerRate' => $this->employerRate, 'employerAmount' => $this->employerAmount];
    }

    /**
     * The line with these values forced on it: its amounts calculated again from what was forced, its
     * explanation saying what the calculation gave.
     *
     * @param bool $negative whether the rubrique's amounts come off (an absence, a reduction, a deduction from the net)
     */
    public function apply(PayslipRow $row, bool $negative): PayslipRow
    {
        // A value forced to what the calculation gave moves nothing (step 4.4c: at levels B, A and 0 every
        // cell is typed): the mensualised salary keeps the contract's amount, an apprentice their two bases.
        $baseMoved = self::moved($this->base, $row->base);
        $employeeRateMoved = self::moved($this->employeeRate, $row->employeeRate);
        $employerRateMoved = self::moved($this->employerRate, $row->employerRate);
        $base = $baseMoved ? $this->base : $row->base;
        $employeeBase = $baseMoved ? $this->base : $row->employeeBaseOrBase();
        $employeeRate = $employeeRateMoved ? $this->employeeRate : $row->employeeRate;
        $employerRate = $employerRateMoved ? $this->employerRate : $row->employerRate;
        $sign = static fn (Money $amount): Money => $negative ? $amount->negated() : $amount;

        $employeeAmount = $row->employeeAmount;
        if (null !== $this->employeeAmount) {
            $employeeAmount = $sign($this->employeeAmount);
        } elseif (($baseMoved || $employeeRateMoved) && null !== $employeeBase && null !== $employeeRate && null !== $row->employeeRateUnit) {
            $employeeAmount = $sign(Money::euros($row->employeeRateUnit->times($employeeBase, $employeeRate)));
        }
        $employerAmount = $row->employerAmount;
        if (null !== $this->employerAmount) {
            $employerAmount = $sign($this->employerAmount);
        } elseif (($baseMoved || $employerRateMoved) && null !== $base && null !== $employerRate && null !== $row->employerRateUnit) {
            $employerAmount = $sign(Money::euros($row->employerRateUnit->times($base, $employerRate)));
        }

        $was = [];
        foreach ([
            'base' => [$this->base, $row->base, $row->baseUnit],
            'taux salarié' => [$this->employeeRate, $row->employeeRate, $row->employeeRateUnit],
            'taux employeur' => [$this->employerRate, $row->employerRate, $row->employerRateUnit],
        ] as $name => [$forced, $value, $unit]) {
            if (null !== $forced) {
                $was[] = \sprintf('%s %s (calcul : %s)', $name, self::shown($forced, $unit), null === $value ? 'aucun' : self::shown($value, $unit));
            }
        }
        foreach (['part salarié' => [$this->employeeAmount, $row->employeeAmount], 'part employeur' => [$this->employerAmount, $row->employerAmount ?? Money::zero()]] as $name => [$forced, $value]) {
            if (null !== $forced) {
                $was[] = \sprintf('%s %s (calcul : %s)', $name, FrenchFormat::amountInEuros($forced), FrenchFormat::amountInEuros($value->abs()));
            }
        }
        $formula = [];
        if (PayrollItemKind::Contribution !== $row->kind) {
            $formula[] = null !== $employeeBase && null !== $employeeRate && null !== $row->baseUnit && null !== $row->employeeRateUnit && null === $this->employeeAmount
                ? \sprintf('%s × %s = %s', $row->baseUnit->format($employeeBase), $row->employeeRateUnit->format($employeeRate), FrenchFormat::amountInEuros($employeeAmount->abs()))
                : 'Montant : '.FrenchFormat::amountInEuros($employeeAmount->abs());
        } else {
            foreach (['salarié' => [$employeeBase, $employeeRate, $row->employeeRateUnit, $employeeAmount, $this->employeeAmount], 'employeur' => [$base, $employerRate, $row->employerRateUnit, $employerAmount, $this->employerAmount]] as $who => [$sideBase, $rate, $unit, $amount, $forcedAmount]) {
                if (null === $amount || ($amount->isZero() && null === $rate)) {
                    continue;
                }
                $formula[] = null !== $sideBase && null !== $rate && null !== $unit && null !== $row->baseUnit && null === $forcedAmount
                    ? \sprintf('Part %s : %s × %s = %s', $who, $row->baseUnit->format($sideBase), $unit->format($rate), FrenchFormat::amountInEuros($amount->abs()))
                    : \sprintf('Montant %s : %s', $who, FrenchFormat::amountInEuros($amount->abs()));
            }
        }
        $explanation = new PayslipExplanation(
            'Valeur forcée. '.implode(' ; ', $formula).'.',
            $row->explanation->base,
            $row->explanation->rate,
            $row->explanation->source,
            ['Valeur forcée à la main : '.implode(', ', $was).'. Le reste du bulletin suit la valeur forcée.', ...$row->explanation->notes],
        );

        return new PayslipRow(
            $row->code,
            $row->label,
            $row->kind,
            $base,
            $row->baseUnit ?? (null === $this->base ? null : PayslipUnit::Euros),
            $employeeRate,
            $row->employeeRateUnit,
            $employeeAmount,
            $explanation,
            $employerRate,
            $row->employerRateUnit,
            $employerAmount,
            $row->line,
            $baseMoved ? null : $row->employeeBase,
            $this->fields(),
            $row->added,
        );
    }

    private static function moved(?BigDecimal $forced, ?BigDecimal $calculated): bool
    {
        return null !== $forced && (null === $calculated || 0 !== $forced->compareTo($calculated));
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $data = [];
        foreach ($this->values() as $field => $value) {
            if ($value instanceof Money) {
                $data[$field] = (string) $value->toDecimal();
            } elseif ($value instanceof BigDecimal) {
                $data[$field] = (string) $value;
            }
        }

        return $data;
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $decimal = static fn (string $field): ?BigDecimal => isset($data[$field]) && \is_string($data[$field]) ? BigDecimal::of($data[$field]) : null;
        $money = static fn (string $field): ?Money => isset($data[$field]) && \is_string($data[$field]) ? Money::euros($data[$field]) : null;

        return new self($decimal('base'), $decimal('employeeRate'), $money('employeeAmount'), $decimal('employerRate'), $money('employerAmount'));
    }

    private static function shown(BigDecimal $value, ?PayslipUnit $unit): string
    {
        return null === $unit ? FrenchFormat::quantity($value) : $unit->format($value);
    }
}
