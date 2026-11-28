<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Service\Payroll\Engine\PayslipAdjustments;
use App\Service\Payroll\Engine\PayslipForcing;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\DecimalParseError;
use App\Service\Shared\DecimalParser;
use App\Service\Shared\FrenchFormat;
use Brick\Math\BigDecimal;

/**
 * The lines of a payslip as a grid à la manière de Sage (step 4.4b; design-ux.md § 4.7): which cells
 * of a line are open - its base and rates where it has them, an amount where its side has no rate,
 * nothing on the general reduction (the year's calculation) -, what each shows, which rubriques may be
 * removed, and the reading of what was typed: a cell whose text changed is forced to it, a cell
 * emptied goes back to the calculation, a cell left as shown keeps what it was.
 *
 * A pure function over the lines shown and the plan; the keys are « code.field » (« 1000.base »).
 */
final class PayslipLineCells
{
    /**
     * The cells of a line one may type in, among PayslipForcing::FIELDS.
     *
     * @return list<string>
     */
    public static function open(PayslipRow $row, ?PayrollItemSpec $spec): array
    {
        if (null !== $spec && PayrollRateSource::GeneralReduction === $spec->employerRate->getSource()) {
            return [];
        }
        $contribution = PayrollItemKind::Contribution === $row->kind;
        $cells = [];
        if (null !== $row->base && (null !== $row->employeeRate || null !== $row->employerRate)) {
            $cells[] = 'base';
        }
        if (null !== $row->employeeRate) {
            $cells[] = 'employeeRate';
        } elseif (!$contribution || !$row->employeeAmount->isZero() || $row->added || $row->isForced('employeeAmount')) {
            $cells[] = 'employeeAmount';
        }
        if (null !== $row->employerRate) {
            $cells[] = 'employerRate';
        } elseif ($contribution && null !== $row->employerAmount && (!$row->employerAmount->isZero() || $row->added || $row->isForced('employerAmount'))) {
            $cells[] = 'employerAmount';
        }

        return $cells;
    }

    /** The value of a cell: a decimal, an amount without its sign; null when the line has none. */
    public static function value(PayslipRow $row, string $field): ?BigDecimal
    {
        return match ($field) {
            'base' => $row->base,
            'employeeRate' => $row->employeeRate,
            'employerRate' => $row->employerRate,
            'employeeAmount' => $row->employeeAmount->abs()->toDecimal(),
            'employerAmount' => $row->employerAmount?->abs()->toDecimal(),
            default => throw new \InvalidArgumentException('No such cell: '.$field),
        };
    }

    /** What a cell shows in its field: a French decimal, an amount without its sign. */
    public static function text(PayslipRow $row, string $field): string
    {
        $value = self::value($row, $field);

        return null === $value ? '' : FrenchFormat::quantity($value, max(2, $value->strippedOfTrailingZeros()->getScale()));
    }

    /**
     * A rubrique the law makes due is not removed from a payslip: the salary of the contract, the
     * withholding tax, a contribution whose rate is the law's (or the establishment's work accident and
     * mobility rates), the general reduction. The others - bonuses, absences, a scheme of the dossier's
     * own - are removed with Ctrl+Suppr.
     */
    public static function removable(PayrollItemSpec $spec): bool
    {
        if (PayrollBase::ContractHours === $spec->base || PayrollBase::NetTaxable === $spec->base) {
            return false;
        }
        if (PayrollItemKind::Contribution !== $spec->kind) {
            return true;
        }
        $legal = [PayrollRateSource::Regulatory, PayrollRateSource::WorkAccident, PayrollRateSource::Mobility, PayrollRateSource::GeneralReduction];

        return !\in_array($spec->employeeRate->getSource(), $legal, true) && !\in_array($spec->employerRate->getSource(), $legal, true);
    }

    /**
     * The adjustments once the cells typed are read.
     *
     * @param list<PayslipRow>      $rows  the lines shown, as calculated with the current adjustments
     * @param list<PayrollItemSpec> $plan
     * @param array<string, string> $typed by « code.field »
     * @param array<string, string> $shown what each cell showed, by the same key
     *
     * @return array{adjustments: PayslipAdjustments, errors: array<string, string>}
     */
    public static function read(array $rows, array $plan, PayslipAdjustments $current, array $typed, array $shown): array
    {
        $specs = [];
        foreach ($plan as $spec) {
            $specs[$spec->code] = $spec;
        }
        $forced = [];
        foreach ($current->forced as $code => $forcing) {
            $forced[(string) $code] = $forcing->toArray();
        }
        $errors = [];
        foreach ($rows as $row) {
            foreach (self::open($row, $specs[$row->code] ?? null) as $field) {
                $key = $row->code.'.'.$field;
                if (!\array_key_exists($key, $typed) || $typed[$key] === ($shown[$key] ?? null)) {
                    continue;
                }
                $text = trim($typed[$key]);
                if ('' === $text) {
                    unset($forced[$row->code][$field]);
                    continue;
                }
                try {
                    $value = DecimalParser::parse($text);
                } catch (DecimalParseError $error) {
                    $errors[$key] = $error->getMessage();
                    continue;
                }
                $fault = self::fault($field, $value);
                if (null !== $fault) {
                    $errors[$key] = $fault;
                    continue;
                }
                $forced[$row->code][$field] = (string) $value;
            }
        }
        if ([] !== $errors) {
            return ['adjustments' => $current, 'errors' => $errors];
        }
        $forcings = [];
        foreach ($forced as $code => $values) {
            $forcings[$code] = PayslipForcing::fromArray($values);
        }

        return ['adjustments' => $current->withForced($forcings), 'errors' => []];
    }

    private static function fault(string $field, BigDecimal $value): ?string
    {
        if ($value->isNegative()) {
            return 'Saisissez la valeur sans signe : la rubrique dit si elle s’ajoute ou se retire.';
        }
        $scale = $value->strippedOfTrailingZeros()->getScale();

        return match ($field) {
            'employeeRate', 'employerRate' => $scale > 4 ? 'Un taux s’écrit avec quatre décimales au plus.' : null,
            'base' => $scale > 4 ? 'Une base s’écrit avec quatre décimales au plus.' : null,
            default => $scale > 2 ? 'Un montant s’écrit au centime, avec deux décimales au plus.' : null,
        };
    }
}
