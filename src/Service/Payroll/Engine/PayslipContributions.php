<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Entity\Payroll\PayrollRate;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollCeiling;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipSection;
use App\Enum\Payroll\PayslipUnit;
use App\Enum\Payroll\SalaryCondition;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Regulatory\RegulatoryTree;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The contributions of a payslip (lot 4, step 4.3b), read from the rubriques of the plan de paie:
 * their base cut into the brackets of the monthly ceiling, their employee's and employer's rates,
 * their conditions (status, headcount, salary above the ceiling) - plus what the rubriques do not
 * say and the law does:
 *
 * - **the reduced rates of 2025** (maladie 7 % up to 2,25 SMIC, allocations familiales 3,45 % up to
 *   3,3 SMIC): a regulatory rate whose value carries « <rate>_reduced » and
 *   « reduced_rate_threshold_smic » beside it is reduced when the gross pay does not exceed that
 *   many SMIC of reference (the general reduction's);
 * - **the CSG and CRDS base**: the gross pay less the abatement for professional expenses within
 *   the ceilings it applies to, plus the employer's provident and health contributions, which it
 *   never abates;
 * - **the overtime**: the employee reduction at the effective rate of the old-age contributions,
 *   within the rate the rubrique reads; the employer's flat deduction on overtime hours only;
 * - **the general reduction** of the millésime (GeneralReductionFormula);
 * - **the progressive regularisation** (step 4.3c): with the year so far (PayslipYearToDate), a bracket
 *   is the year's bracket - the year's gross pay between multiples of the year's ceilings - less the
 *   year's before this payslip, so a month may carry a ceiling left unused or give a tranche 2 back
 *   (a negative base); the contribution due above the ceiling looks at the year; the general reduction
 *   is the year's less what was made.
 *
 * Every share is its base times its rate, rounded half up to the cent on its own (DECISIONS 4.3b).
 * A rubrique whose base is nil, or whose sides have nothing to apply, makes no line.
 */
final class PayslipContributions
{
    /** The regulatory keys whose premium makes an hour paid overtime, or complementary hours. */
    private const string OVERTIME_PREMIUM = 'social.overtime.premium';
    private const string COMPLEMENTARY_PREMIUM = 'social.overtime.complementary_premium';

    private readonly BigDecimal $gross;
    private readonly BigDecimal $ceiling;
    private readonly BigDecimal $overtimeHours;
    private readonly BigDecimal $premiumHours;
    private readonly BigDecimal $premiumPay;
    /** @var list<string> */
    private readonly array $premiumCodes;
    private ?GeneralReductionFormula $formula = null;
    private ?Money $smic = null;
    /** @var array<string, PayslipRow> */
    private array $done = [];
    private ?GeneralReduction $reduction = null;

    /** @param list<PayslipRow> $grossRows */
    private function __construct(private readonly PayslipInput $input, array $grossRows, Money $gross)
    {
        $this->gross = $gross->toDecimal();
        $this->ceiling = $input->regulatory->decimal('social.pmss');
        $overtime = BigDecimal::zero();
        $premium = BigDecimal::zero();
        $pay = BigDecimal::zero();
        $codes = [];
        $kinds = [];
        foreach ($input->plan as $spec) {
            if (PayrollItemKind::Gross === $spec->kind && PayrollRateSource::HourlyRateWithPremium === $spec->employeeRate->getSource()) {
                $kinds[$spec->code] = $spec->employeeRate->getReferenceKey();
            }
        }
        foreach ($grossRows as $row) {
            $key = $kinds[$row->code] ?? null;
            if ((self::OVERTIME_PREMIUM !== $key && self::COMPLEMENTARY_PREMIUM !== $key) || null === $row->base) {
                continue;
            }
            $premium = $premium->plus($row->base);
            $pay = $pay->plus($row->employeeAmount->toDecimal());
            $codes[] = $row->code;
            if (self::OVERTIME_PREMIUM === $key) {
                $overtime = $overtime->plus($row->base);
            }
        }
        $this->overtimeHours = $overtime;
        $this->premiumHours = $premium;
        $this->premiumPay = $pay;
        $this->premiumCodes = $codes;
    }

    /**
     * @param list<PayrollItemSpec> $ordered the plan in its order
     * @param list<PayslipRow>      $grossRows
     *
     * @return array{rows: list<PayslipRow>, reduction: ?GeneralReduction, smic: Money, overtimePay: BigDecimal}
     */
    public static function calculate(PayslipInput $input, array $ordered, array $grossRows, Money $gross): array
    {
        $self = new self($input, $grossRows, $gross);
        $applicable = array_values(array_filter($ordered, $self->applies(...)));
        // What a base reads must be calculated first: the provident shares before the CSG base and
        // the forfait social, the old-age contributions before the overtime reduction, all before
        // the general reduction (which reads the gross pay alone, but closes the list).
        for ($phase = 0; $phase <= 3; ++$phase) {
            foreach ($applicable as $spec) {
                if (self::phase($spec) === $phase) {
                    $row = $self->row($spec);
                    if (null !== $row) {
                        $self->done[$spec->code] = $row;
                    }
                }
            }
        }
        $rows = [];
        foreach ($applicable as $spec) {
            if (isset($self->done[$spec->code])) {
                $rows[] = $self->done[$spec->code];
            }
        }

        return ['rows' => $rows, 'reduction' => $self->reduction, 'smic' => $self->smic(), 'overtimePay' => $self->premiumPay];
    }

    private function applies(PayrollItemSpec $spec): bool
    {
        return PayrollItemKind::Contribution === $spec->kind
            && $spec->status->holds($this->input->employee->executive)
            && $spec->headcount->holds($this->input->establishment->headcount)
            && (SalaryCondition::Always === $spec->salary || $this->aboveCeiling());
    }

    /** The salary exceeds the ceiling: the month's alone, or the year's against the year's ceilings. */
    private function aboveCeiling(): bool
    {
        $before = $this->input->yearToDate;
        if ($before->isEmpty()) {
            return $this->gross->isGreaterThan($this->ceiling);
        }

        return $before->gross->toDecimal()->plus($this->gross)->isGreaterThan($before->ceiling->toDecimal()->plus($this->ceiling));
    }

    private static function phase(PayrollItemSpec $spec): int
    {
        return match (true) {
            PayrollRateSource::GeneralReduction === $spec->employerRate->getSource() => 3,
            PayrollBase::OvertimePay === $spec->base => 2,
            PayrollBase::CsgBase === $spec->base, PayrollBase::ProvidentEmployer === $spec->base => 1,
            default => 0,
        };
    }

    private function row(PayrollItemSpec $spec): ?PayslipRow
    {
        if (PayrollRateSource::GeneralReduction === $spec->employerRate->getSource()) {
            return $this->generalReductionRow($spec);
        }
        $base = $this->base($spec);
        if (null !== $base && $base['value']->isZero()) {
            return null;
        }
        $employee = $this->side($spec, $spec->employeeRate, $base);
        $employer = $this->side($spec, $spec->employerRate, $base);
        if (null === $employee && null === $employer) {
            return null;
        }

        $formulas = [];
        $rates = [];
        $notes = [];
        foreach (['salarié' => $employee, 'employeur' => $employer] as $who => $side) {
            if (null === $side) {
                continue;
            }
            $formulas[] = (null === $base || null === $side['rate'] || null === $side['unit']
                ? 'Montant '.$who.' : '.FrenchFormat::amountInEuros($side['amount']->abs())
                : \sprintf('Part %s : %s × %s = %s', $who, $base['unit']->format($base['value']), $side['unit']->format($side['rate']), FrenchFormat::amountInEuros($side['amount']->abs()))).($spec->subtracts ? ', en déduction' : '');
            $rates[] = ucfirst($who).' : '.$side['origin'];
            array_push($notes, ...$side['notes']);
        }

        return new PayslipRow(
            $spec->code,
            $spec->label,
            $spec->kind,
            $base['value'] ?? null,
            $base['unit'] ?? null,
            $employee['rate'] ?? null,
            $employee['unit'] ?? null,
            $employee['amount'] ?? Money::zero(),
            new PayslipExplanation(implode(' ; ', $formulas).'.', $base['origin'] ?? 'Sans base : la rubrique est un forfait.', implode(' ', $rates), $employee['source'] ?? $employer['source'] ?? null, $notes),
            $employer['rate'] ?? null,
            $employer['unit'] ?? null,
            $employer['amount'] ?? null,
            $spec->line,
        );
    }

    /**
     * The base of a rubrique, null for a flat one.
     *
     * @return array{value: BigDecimal, unit: PayslipUnit, origin: string}|null
     */
    private function base(PayrollItemSpec $spec): ?array
    {
        $euros = PayslipUnit::Euros;

        return match ($spec->base) {
            PayrollBase::Gross => $this->bracket($spec->ceiling),
            PayrollBase::MonthlyCeiling => ['value' => $this->ceiling, 'unit' => $euros, 'origin' => 'Plafond mensuel de la sécurité sociale : '.$euros->format($this->ceiling).'.'],
            PayrollBase::Flat => null,
            PayrollBase::CsgBase => $this->csgBase(),
            PayrollBase::ProvidentEmployer => (function () use ($euros): array {
                $shares = $this->providentShares();

                return ['value' => $shares, 'unit' => $euros, 'origin' => 'Part patronale des cotisations de prévoyance et de complémentaire santé du bulletin : '.$euros->format($shares).'.'];
            })(),
            PayrollBase::OvertimePay => ['value' => $this->premiumPay, 'unit' => $euros, 'origin' => \sprintf('Rémunération des heures supplémentaires et complémentaires du mois, majorations comprises (rubriques %s) : %s.', implode(', ', $this->premiumCodes), $euros->format($this->premiumPay))],
            PayrollBase::OvertimeHours => ['value' => $this->overtimeHours, 'unit' => PayslipUnit::Hours, 'origin' => 'Heures supplémentaires du mois (les heures complémentaires n’y ouvrent pas droit) : '.PayslipUnit::Hours->format($this->overtimeHours).'.'],
            default => throw new \LogicException(\sprintf('Rubrique %s : une cotisation ne repose pas sur « %s ».', $spec->code, $spec->base->label())),
        };
    }

    /**
     * The part of the gross pay between two multiples of the monthly ceiling (social.ceiling_brackets);
     * with the year so far, the year's part less the part before this payslip.
     *
     * @return array{value: BigDecimal, unit: PayslipUnit, origin: string}
     */
    private function bracket(PayrollCeiling $ceiling): array
    {
        $euros = PayslipUnit::Euros;
        if (PayrollCeiling::None === $ceiling) {
            return ['value' => $this->gross, 'unit' => $euros, 'origin' => 'Salaire brut du mois : '.$euros->format($this->gross).'.'];
        }
        $from = $this->input->regulatory->decimal('social.ceiling_brackets', $ceiling->value, 'from');
        $to = $this->input->regulatory->decimal('social.ceiling_brackets', $ceiling->value, 'to');
        $before = $this->input->yearToDate;
        if ($before->isEmpty()) {
            $low = $from->multipliedBy($this->ceiling);
            $high = $to->multipliedBy($this->ceiling);

            return ['value' => self::part($this->gross, $this->ceiling, $from, $to), 'unit' => $euros, 'origin' => \sprintf(
                '%s. Part du brut (%s) entre %s et %s plafond%s mensuel%s (%s et %s ; plafond %s).',
                $ceiling->label(),
                $euros->format($this->gross),
                FrenchFormat::number($from),
                FrenchFormat::number($to),
                $to->isGreaterThan(1) ? 's' : '',
                $to->isGreaterThan(1) ? 's' : '',
                $euros->format($low),
                $euros->format($high),
                $euros->format($this->ceiling),
            )];
        }
        $grossBefore = $before->gross->toDecimal();
        $ceilingBefore = $before->ceiling->toDecimal();
        $yearGross = $grossBefore->plus($this->gross);
        $yearCeiling = $ceilingBefore->plus($this->ceiling);
        $partBefore = self::part($grossBefore, $ceilingBefore, $from, $to);
        $yearPart = self::part($yearGross, $yearCeiling, $from, $to);

        return ['value' => $yearPart->minus($partBefore), 'unit' => $euros, 'origin' => \sprintf(
            '%s, régularisée sur l’année : part du brut de l’année (%s) entre %s et %s fois les plafonds de l’année (%s) = %s, moins la part des mois précédents (brut %s, plafonds %s) = %s ; %s pour ce mois.',
            $ceiling->label(),
            $euros->format($yearGross),
            FrenchFormat::number($from),
            FrenchFormat::number($to),
            $euros->format($yearCeiling),
            $euros->format($yearPart),
            $euros->format($grossBefore),
            $euros->format($ceilingBefore),
            $euros->format($partBefore),
            $euros->format($yearPart->minus($partBefore)),
        )];
    }

    /** The part of a gross pay between from and to times a ceiling. */
    private static function part(BigDecimal $gross, BigDecimal $ceiling, BigDecimal $from, BigDecimal $to): BigDecimal
    {
        $low = $from->multipliedBy($ceiling);

        return $gross->isLessThan($low) ? BigDecimal::zero() : BigDecimal::min($gross, $to->multipliedBy($ceiling))->minus($low);
    }

    /**
     * The CSG and CRDS base: the gross pay less the abatement within that many ceilings, the rest
     * whole, rounded to the cent; plus the employer's provident and health shares, never abated.
     *
     * @return array{value: BigDecimal, unit: PayslipUnit, origin: string}
     */
    private function csgBase(): array
    {
        $euros = PayslipUnit::Euros;
        $percent = PayslipUnit::Percent;
        $abatement = $this->input->regulatory->decimal('social.csg_abatement', 'rate');
        $limit = $this->input->regulatory->decimal('social.csg_abatement', 'limit_pss')->multipliedBy($this->ceiling);
        $kept = BigDecimal::of(100)->minus($abatement);
        $abated = BigDecimal::min($this->gross, $limit);
        $whole = $this->gross->minus($abated);
        $value = $percent->times($abated, $kept)->plus($whole);
        $shares = $this->providentShares();
        $text = \sprintf('%s du brut (%s × %s, abattement de %s pour frais professionnels', $percent->format($kept), $euros->format($abated), $percent->format($kept), $percent->format($abatement));
        $text .= $whole->isPositive() ? \sprintf(' dans la limite de %s ; au-delà, %s entiers)', $euros->format($limit), $euros->format($whole)) : ')';

        return ['value' => $value->plus($shares), 'unit' => $euros, 'origin' => \sprintf('%s = %s, plus la part patronale de prévoyance et de complémentaire santé, sans abattement (%s) : %s.', $text, $euros->format($value), $euros->format($shares), $euros->format($value->plus($shares)))];
    }

    /** The employer's shares of the rubriques on the « complémentaire santé » and « prévoyance » lines. */
    private function providentShares(): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($this->done as $row) {
            if (PayslipLine::ComplementaryHealth === $row->line || PayslipLine::ComplementaryProvident === $row->line) {
                $total = $total->plus(($row->employerAmount ?? Money::zero())->toDecimal());
            }
        }

        return $total;
    }

    /**
     * One side of a contribution: its rate, the amount on the base (negative for a reduction), and
     * where the rate comes from. Null when the side has nothing to apply.
     *
     * @param array{value: BigDecimal, unit: PayslipUnit, origin: string}|null $base
     *
     * @return array{rate: ?BigDecimal, unit: ?PayslipUnit, amount: Money, origin: string, source: ?PayslipSource, notes: list<string>}|null
     */
    private function side(PayrollItemSpec $spec, PayrollRate $rate, ?array $base): ?array
    {
        $establishment = $this->input->establishment;
        $percent = PayslipUnit::Percent;
        $notes = [];
        $source = null;
        switch ($rate->getSource()) {
            case PayrollRateSource::None:
                return null;
            case PayrollRateSource::Amount:
                $amount = $rate->getAmount() ?? throw new \LogicException(\sprintf('Rubrique %s : un forfait sans montant.', $spec->code));

                return ['rate' => null, 'unit' => null, 'amount' => $spec->subtracts ? $amount->negated() : $amount, 'origin' => 'Forfait du dossier : '.FrenchFormat::amountInEuros($amount).'.', 'source' => null, 'notes' => []];
            case PayrollRateSource::Fixed:
                $value = BigDecimal::of((string) $rate->getPercent());
                $origin = 'Taux du dossier : '.$percent->format($value).'.';
                break;
            case PayrollRateSource::WorkAccident:
                $value = $establishment->workAccidentRate;
                $origin = 'Taux d’accident du travail notifié à l’établissement : '.$percent->format($value).'.';
                break;
            case PayrollRateSource::Mobility:
                if (null === $establishment->mobilityRate) {
                    return null;
                }
                $value = $establishment->mobilityRate;
                $origin = 'Taux de versement mobilité du territoire de l’établissement : '.$percent->format($value).'.';
                break;
            case PayrollRateSource::Regulatory:
                $key = (string) $rate->getReferenceKey();
                $value = $this->input->regulatory->decimal($key, ...$rate->pathSegments());
                $source = PayslipSource::of($this->input->regulatory, $key, $rate->getReferencePath());
                $origin = $this->regulatoryOrigin($key, $rate->getReferencePath(), $value, null !== $base && PayslipUnit::Hours === $base['unit']);
                $reduced = $this->reducedRate($key, $rate->pathSegments());
                if (null !== $reduced) {
                    $notes[] = $reduced['note'];
                    if (null !== $reduced['rate']) {
                        $value = $reduced['rate'];
                        $origin = $reduced['origin'];
                    }
                }
                if (PayrollBase::OvertimePay === $spec->base) {
                    $effective = $this->oldAgeEffectiveRate();
                    $notes[] = $effective['note'];
                    if ($effective['rate']->isLessThan($value)) {
                        $origin .= ' Taux appliqué : le taux effectif des cotisations salariales de vieillesse, '.$percent->format($effective['rate']).', inférieur.';
                        $value = $effective['rate'];
                    }
                }
                break;
            default:
                throw new \LogicException(\sprintf('Rubrique %s : « %s » ne donne pas le taux d’une cotisation.', $spec->code, $rate->getSource()->label()));
        }
        if (null === $base) {
            throw new \LogicException(\sprintf('Rubrique %s : un forfait n’a qu’un montant.', $spec->code));
        }
        $unit = PayslipUnit::Hours === $base['unit'] ? PayslipUnit::EurosPerHour : $percent;
        $amount = Money::euros($unit->times($base['value'], $value));

        return ['rate' => $value, 'unit' => $unit, 'amount' => $spec->subtracts ? $amount->negated() : $amount, 'origin' => $origin, 'source' => $source, 'notes' => $notes];
    }

    private function regulatoryOrigin(string $key, ?string $path, BigDecimal $value, bool $perHour): string
    {
        $read = $this->input->regulatory->value($key);
        $shown = $perHour ? PayslipUnit::EurosPerHour->format($value).' par heure' : PayslipUnit::Percent->format($value);

        return \sprintf('%s : %s au %s (%s%s).', $read->getLabel(), $shown, FrenchFormat::date($this->input->regulatory->day), $key, null === $path ? '' : ' › '.str_replace('.', ' › ', $path));
    }

    /**
     * The reduced rate a regulatory value carries beside the rate read (2025's maladie and allocations
     * familiales): null when it carries none; otherwise the note saying which applies, and the
     * reduced rate when the gross pay does not exceed the threshold.
     *
     * @param list<int|string> $path
     *
     * @return array{rate: ?BigDecimal, origin: string, note: string}|null
     */
    private function reducedRate(string $key, array $path): ?array
    {
        if ([] === $path) {
            return null;
        }
        $leaf = (string) array_pop($path);
        $tree = $this->input->regulatory->value($key)->getTree();
        $reduced = RegulatoryTree::at($tree, [...$path, $leaf.'_reduced']);
        $threshold = RegulatoryTree::at($tree, [...$path, 'reduced_rate_threshold_smic']);
        if (\is_array($reduced) || \is_array($threshold) || !RegulatoryTree::isNumber($reduced) || !RegulatoryTree::isNumber($threshold)) {
            return null;
        }
        $rate = BigDecimal::of((string) $reduced);
        $times = BigDecimal::of((string) $threshold);
        $smic = $this->smic();
        $limit = $times->multipliedBy($smic->toDecimal());
        $euros = PayslipUnit::Euros;
        $percent = PayslipUnit::Percent;
        $applies = $this->gross->isLessThanOrEqualTo($limit);
        $note = \sprintf('Taux réduit de %s jusqu’à %s SMIC : %s × %s = %s ; brut %s, %s.', $percent->format($rate), FrenchFormat::number($times), FrenchFormat::number($times), FrenchFormat::amountInEuros($smic), $euros->format($limit->toScale(2, RoundingMode::HalfUp)), $euros->format($this->gross), $applies ? 'taux réduit' : 'taux plein');

        return ['rate' => $applies ? $rate : null, 'origin' => \sprintf('Taux réduit (%s › %s_reduced) : %s, le brut ne dépassant pas %s SMIC de référence.', $key, $leaf, $percent->format($rate), FrenchFormat::number($times)), 'note' => $note];
    }

    /**
     * The effective rate of the employee's old-age contributions (the retirement lines of the
     * payslip): their rates weighted by their bases over the gross pay - their sum when everything is
     * under the ceiling -, at four decimals.
     *
     * @return array{rate: BigDecimal, note: string}
     */
    private function oldAgeEffectiveRate(): array
    {
        $weighted = BigDecimal::zero();
        $codes = [];
        foreach ($this->done as $row) {
            if (PayslipSection::Retirement !== $row->line?->section() || null === $row->employeeRate || null === $row->base) {
                continue;
            }
            $weighted = $weighted->plus($row->employeeRate->multipliedBy($row->base));
            $codes[] = $row->code;
        }
        $rate = $this->gross->isZero() ? BigDecimal::zero() : $weighted->dividedBy($this->gross, 4, RoundingMode::HalfUp)->strippedOfTrailingZeros();

        return ['rate' => $rate, 'note' => \sprintf('Taux effectif des cotisations salariales de vieillesse (rubriques %s) : Σ taux × base ÷ brut = %s.', implode(', ', $codes), PayslipUnit::Percent->format($rate))];
    }

    private function formula(): GeneralReductionFormula
    {
        return $this->formula ??= GeneralReductionFormula::for($this->input->regulatory);
    }

    private function smic(): Money
    {
        return $this->smic ??= $this->formula()->smic($this->input->employee->monthlyHours, $this->input->establishment->monthlyHours, $this->premiumHours);
    }

    private function generalReductionRow(PayrollItemSpec $spec): ?PayslipRow
    {
        $reduction = $this->formula()->regularised(Money::euros($this->gross), $this->smic(), $this->input->yearToDate, $this->input->establishment->headcount);
        if (null === $reduction || $reduction->amount->isZero()) {
            $this->reduction = $reduction;

            return null;
        }
        $this->reduction = $reduction;
        $coefficient = PayslipUnit::Coefficient;
        $regularised = !$this->input->yearToDate->isEmpty() || !$this->input->yearToDate->generalReduction->isZero();
        $formula = $regularised
            ? \sprintf('Part employeur : %s × %s = %s pour l’année, moins %s déjà faite = %s%s.', PayslipUnit::Euros->format($reduction->yearRemuneration->toDecimal()), $coefficient->format($reduction->coefficient), FrenchFormat::amountInEuros($reduction->yearAmount), FrenchFormat::amountInEuros($this->input->yearToDate->generalReduction), FrenchFormat::amountInEuros($reduction->amount->abs()), $reduction->amount->isNegative() ? ', réintégrée' : ', en déduction')
            : \sprintf('Part employeur : %s × %s = %s, en déduction.', PayslipUnit::Euros->format($this->gross), $coefficient->format($reduction->coefficient), FrenchFormat::amountInEuros($reduction->amount));

        return new PayslipRow(
            $spec->code,
            $spec->label,
            $spec->kind,
            $this->gross,
            PayslipUnit::Euros,
            null,
            null,
            Money::zero(),
            new PayslipExplanation(
                $formula,
                \sprintf('Rémunération brute du mois : %s. %s', PayslipUnit::Euros->format($this->gross), $this->formula()->smicExplanation($this->input->employee->monthlyHours, $this->input->establishment->monthlyHours, $this->premiumHours, $reduction->smic)),
                'Coefficient de la réduction générale du millésime : '.$coefficient->format($reduction->coefficient).($regularised ? ', calculé sur l’année.' : '.'),
                $reduction->source,
                $reduction->steps,
            ),
            $reduction->coefficient,
            $coefficient,
            $reduction->amount->negated(),
            $spec->line,
        );
    }
}
