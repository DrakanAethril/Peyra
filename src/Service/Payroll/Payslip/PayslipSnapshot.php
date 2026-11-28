<?php

declare(strict_types=1);

namespace App\Service\Payroll\Payslip;

use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Engine\GeneralReduction;
use App\Service\Payroll\Engine\Payslip;
use App\Service\Payroll\Engine\PayslipExplanation;
use App\Service\Payroll\Engine\PayslipRow;
use App\Service\Payroll\Engine\PayslipSource;
use App\Service\Payroll\Engine\PayslipTotals;
use App\Service\Payroll\Engine\PayslipYearToDate;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;

/**
 * A validated payslip frozen (step 4.4b): the engine's Payslip written whole into a JSON column at the
 * validation - lines with their explanations, figures, general reduction, year to date - and read back
 * as the same Payslip, so that a validated payslip never moves when the plan, the fiche or a regulatory
 * value changes afterwards. Beside it, what the month added to each figure of the year (`month`, in
 * cents, negative when a regularisation gave back): the year to date of a later payslip is the sum of
 * these over the year's validated payslips.
 */
final class PayslipSnapshot
{
    /** The figures of PayslipYearToDate, in its constructor's order. */
    public const array YEAR_FIGURES = ['gross', 'ceiling', 'smic', 'generalReduction', 'exemptOvertime', 'netTaxable', 'apprenticeExempt'];

    /** @return array<string, mixed> */
    public static function toArray(Payslip $payslip, PayslipYearToDate $before): array
    {
        $after = self::figures($payslip->yearToDate);
        $was = self::figures($before);
        $month = [];
        foreach (self::YEAR_FIGURES as $figure) {
            $month[$figure] = $after[$figure] - $was[$figure];
        }

        return [
            'rows' => array_map(self::row(...), $payslip->rows),
            'gross' => $payslip->gross->cents,
            'generalReduction' => null === $payslip->generalReduction ? null : self::reduction($payslip->generalReduction),
            'totals' => self::totals($payslip->totals),
            'yearToDate' => $after,
            'month' => $month,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): Payslip
    {
        $read = new SnapshotReader($data);
        $reduction = $read->nullableMap('generalReduction');

        return new Payslip(
            array_map(self::readRow(...), $read->maps('rows')),
            $read->money('gross'),
            null === $reduction ? null : self::readReduction($reduction),
            self::readTotals($read->map('totals')),
            self::yearToDate($read->map('yearToDate')),
        );
    }

    /**
     * What a frozen payslip added to each figure of the year, in cents.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, int>
     */
    public static function monthFigures(array $data): array
    {
        $month = (new SnapshotReader($data))->map('month');
        $figures = [];
        foreach (self::YEAR_FIGURES as $figure) {
            $figures[$figure] = $month->int($figure);
        }

        return $figures;
    }

    /**
     * The year to date from the month figures of the year's validated payslips, summed.
     *
     * @param list<array<string, int>> $months
     */
    public static function yearToDateOf(array $months): PayslipYearToDate
    {
        $sum = array_fill_keys(self::YEAR_FIGURES, 0);
        foreach ($months as $month) {
            foreach (self::YEAR_FIGURES as $figure) {
                $sum[$figure] += $month[$figure] ?? 0;
            }
        }

        return new PayslipYearToDate(...array_map(static fn (int $cents): Money => Money::cents(max(0, $cents)), array_values($sum)));
    }

    /** @return array<string, int> */
    private static function figures(PayslipYearToDate $year): array
    {
        return [
            'gross' => $year->gross->cents,
            'ceiling' => $year->ceiling->cents,
            'smic' => $year->smic->cents,
            'generalReduction' => $year->generalReduction->cents,
            'exemptOvertime' => $year->exemptOvertime->cents,
            'netTaxable' => $year->netTaxable->cents,
            'apprenticeExempt' => $year->apprenticeExempt->cents,
        ];
    }

    private static function yearToDate(SnapshotReader $read): PayslipYearToDate
    {
        return new PayslipYearToDate(...array_map(static fn (string $figure): Money => Money::cents(max(0, $read->int($figure))), self::YEAR_FIGURES));
    }

    /** @return array<string, mixed> */
    private static function row(PayslipRow $row): array
    {
        return [
            'code' => $row->code,
            'label' => $row->label,
            'kind' => $row->kind->value,
            'base' => self::decimal($row->base),
            'baseUnit' => $row->baseUnit?->value,
            'employeeRate' => self::decimal($row->employeeRate),
            'employeeRateUnit' => $row->employeeRateUnit?->value,
            'employeeAmount' => $row->employeeAmount->cents,
            'explanation' => self::explanation($row->explanation),
            'employerRate' => self::decimal($row->employerRate),
            'employerRateUnit' => $row->employerRateUnit?->value,
            'employerAmount' => $row->employerAmount?->cents,
            'line' => $row->line?->value,
            'employeeBase' => self::decimal($row->employeeBase),
            'forced' => $row->forced,
            'added' => $row->added,
        ];
    }

    private static function readRow(SnapshotReader $read): PayslipRow
    {
        $unit = static fn (?string $value): ?PayslipUnit => null === $value ? null : PayslipUnit::from($value);
        $line = $read->nullableString('line');

        return new PayslipRow(
            $read->string('code'),
            $read->string('label'),
            PayrollItemKind::from($read->string('kind')),
            $read->nullableDecimal('base'),
            $unit($read->nullableString('baseUnit')),
            $read->nullableDecimal('employeeRate'),
            $unit($read->nullableString('employeeRateUnit')),
            $read->money('employeeAmount'),
            self::readExplanation($read->map('explanation')),
            $read->nullableDecimal('employerRate'),
            $unit($read->nullableString('employerRateUnit')),
            $read->nullableMoney('employerAmount'),
            null === $line ? null : PayslipLine::from($line),
            $read->nullableDecimal('employeeBase'),
            $read->strings('forced'),
            $read->bool('added'),
        );
    }

    /** @return array<string, mixed> */
    private static function explanation(PayslipExplanation $explanation): array
    {
        $source = $explanation->source;

        return [
            'formula' => $explanation->formula,
            'base' => $explanation->base,
            'rate' => $explanation->rate,
            'source' => null === $source ? null : self::source($source),
            'notes' => $explanation->notes,
        ];
    }

    private static function readExplanation(SnapshotReader $read): PayslipExplanation
    {
        $source = $read->nullableMap('source');

        return new PayslipExplanation($read->string('formula'), $read->string('base'), $read->string('rate'), null === $source ? null : self::readSource($source), $read->strings('notes'));
    }

    /** @return array<string, mixed> */
    private static function source(PayslipSource $source): array
    {
        return ['key' => $source->key, 'path' => $source->path, 'label' => $source->label, 'effectiveFrom' => $source->effectiveFrom->format('Y-m-d'), 'url' => $source->url, 'verified' => $source->verified];
    }

    private static function readSource(SnapshotReader $read): PayslipSource
    {
        return new PayslipSource($read->string('key'), $read->nullableString('path'), $read->string('label'), new \DateTimeImmutable($read->string('effectiveFrom')), $read->nullableString('url'), $read->bool('verified'));
    }

    /** @return array<string, mixed> */
    private static function reduction(GeneralReduction $reduction): array
    {
        return [
            'regime' => $reduction->regime,
            'remuneration' => $reduction->remuneration->cents,
            'smic' => $reduction->smic->cents,
            'coefficient' => (string) $reduction->coefficient,
            'amount' => $reduction->amount->cents,
            'urssafShare' => $reduction->urssafShare->cents,
            'retirementShare' => $reduction->retirementShare->cents,
            'steps' => $reduction->steps,
            'source' => self::source($reduction->source),
            'yearRemuneration' => $reduction->yearRemuneration->cents,
            'yearSmic' => $reduction->yearSmic->cents,
            'yearAmount' => $reduction->yearAmount->cents,
        ];
    }

    private static function readReduction(SnapshotReader $read): GeneralReduction
    {
        return new GeneralReduction(
            $read->string('regime'),
            $read->money('remuneration'),
            $read->money('smic'),
            BigDecimal::of($read->string('coefficient')),
            $read->money('amount'),
            $read->money('urssafShare'),
            $read->money('retirementShare'),
            $read->strings('steps'),
            self::readSource($read->map('source')),
            $read->money('yearRemuneration'),
            $read->money('yearSmic'),
            $read->money('yearAmount'),
        );
    }

    /** @return array<string, mixed> */
    private static function totals(PayslipTotals $totals): array
    {
        return [
            'employeeContributions' => $totals->employeeContributions->cents,
            'employerContributions' => $totals->employerContributions->cents,
            'netSocial' => $totals->netSocial->cents,
            'netTaxable' => $totals->netTaxable->cents,
            'exemptOvertime' => $totals->exemptOvertime->cents,
            'netBeforeTax' => $totals->netBeforeTax->cents,
            'withholdingRate' => (string) $totals->withholdingRate,
            'neutralRate' => $totals->neutralRate,
            'withholdingTax' => $totals->withholdingTax->cents,
            'netPay' => $totals->netPay->cents,
            'employerCost' => $totals->employerCost->cents,
            'explanations' => array_map(self::explanation(...), $totals->explanations),
            'apprenticeExempt' => $totals->apprenticeExempt?->cents,
        ];
    }

    private static function readTotals(SnapshotReader $read): PayslipTotals
    {
        $explanations = [];
        foreach ($read->namedMaps('explanations') as $name => $explanation) {
            $explanations[$name] = self::readExplanation($explanation);
        }

        return new PayslipTotals(
            $read->money('employeeContributions'),
            $read->money('employerContributions'),
            $read->money('netSocial'),
            $read->money('netTaxable'),
            $read->money('exemptOvertime'),
            $read->money('netBeforeTax'),
            BigDecimal::of($read->string('withholdingRate')),
            $read->bool('neutralRate'),
            $read->money('withholdingTax'),
            $read->money('netPay'),
            $read->money('employerCost'),
            $explanations,
            $read->nullableMoney('apprenticeExempt'),
        );
    }

    private static function decimal(?BigDecimal $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
