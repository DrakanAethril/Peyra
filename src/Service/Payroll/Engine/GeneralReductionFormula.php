<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\HeadcountCondition;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Regulatory\RegulatorySnapshot;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The general reduction of employer contributions (lot 4, step 4.3b): **one formula per millésime**,
 * chosen by the regulatory value `social.general_reduction.regime` in force on the payslip's day -
 * « rgcp », the formula of article D241-7 (2025), or « rgdu », the single degressive reduction
 * (2026) - each reading its own parameters. Calculated on the month (calculate()), or regularised
 * over the year so far (regularised(), step 4.3c: « régularisation progressive »): the coefficient of
 * the year's remuneration and SMIC, times that remuneration, less the reduction already made - a month
 * may then give some back. The parameters are those of the payslip's day, for the whole year.
 *
 * Both compare the month's gross remuneration to a SMIC of reference: the monthly SMIC the
 * millésime retains (its value on 1 January, whatever happens in June), prorated on the contract's
 * hours against the establishment's collective hours and rounded to the cent - the amount the DSN
 * declares -, plus the hourly SMIC of reference times the overtime and complementary hours paid.
 */
abstract class GeneralReductionFormula
{
    protected const int SCALE = 20;

    public const string REGIME_KEY = 'social.general_reduction.regime';

    final public function __construct(protected readonly RegulatorySnapshot $regulatory)
    {
    }

    public static function for(RegulatorySnapshot $regulatory): self
    {
        $regime = $regulatory->text(self::REGIME_KEY);

        return match ($regime) {
            GeneralReductionRgcp::REGIME => new GeneralReductionRgcp($regulatory),
            GeneralReductionRgdu::REGIME => new GeneralReductionRgdu($regulatory),
            default => throw new \LogicException(\sprintf('Réduction générale : formule « %s » inconnue (%s).', $regime, self::REGIME_KEY)),
        };
    }

    /** The key of the millésime's parameters. */
    abstract protected function parametersKey(): string;

    /** The key holding the Agirc-Arrco rate the coefficient covers (« agirc_arrco »). */
    abstract protected function coveredContributionsKey(): string;

    /**
     * The coefficient before its rounding, and the maximum it may not exceed - the T of the
     * imputation - for that headcount band (« lt_50 », « ge_50 »).
     *
     * @return array{raw: BigDecimal, max: BigDecimal, steps: list<string>}
     */
    abstract protected function coefficient(BigDecimal $remuneration, BigDecimal $smic, string $band): array;

    /** The coefficient's maximum for that headcount band, the T of the imputation. */
    abstract protected function maximum(string $band): BigDecimal;

    /**
     * The SMIC of reference of the month (see the class), corrected in a month with an absence by the ratio
     * of the salary due to the salary of the whole month (PayslipPresence::ratio(), step 4.3d), rounded once.
     *
     * @param array{due: BigDecimal, full: BigDecimal, text: string}|null $presence
     */
    public function smic(BigDecimal $contractHours, BigDecimal $collectiveHours, BigDecimal $premiumHours, ?array $presence = null): Money
    {
        $monthly = $this->regulatory->decimal($this->parametersKey(), 'smic_monthly_reference');
        $hourly = $this->regulatory->decimal($this->parametersKey(), 'smic_hourly_reference');
        $prorated = null === $presence
            ? Money::euros($monthly->multipliedBy($contractHours)->dividedBy($collectiveHours, 2, RoundingMode::HalfUp))
            : Money::euros($monthly->multipliedBy($contractHours)->multipliedBy($presence['due'])->dividedBy($collectiveHours->multipliedBy($presence['full']), 2, RoundingMode::HalfUp));

        return $prorated->plus(Money::euros(PayslipUnit::EurosPerHour->times($premiumHours, $hourly)));
    }

    /**
     * The explanation of the SMIC of reference, for the line.
     *
     * @param array{due: BigDecimal, full: BigDecimal, text: string}|null $presence
     */
    public function smicExplanation(BigDecimal $contractHours, BigDecimal $collectiveHours, BigDecimal $premiumHours, ?array $presence, Money $smic): string
    {
        $monthly = $this->regulatory->decimal($this->parametersKey(), 'smic_monthly_reference');
        $hourly = $this->regulatory->decimal($this->parametersKey(), 'smic_hourly_reference');
        $text = \sprintf('SMIC de référence du mois : %s', PayslipUnit::Euros->format($monthly));
        if (!$contractHours->isEqualTo($collectiveHours)) {
            $text .= \sprintf(' × %s ÷ %s', PayslipUnit::Hours->format($contractHours), PayslipUnit::Hours->format($collectiveHours));
        }
        if (null !== $presence) {
            $text .= \sprintf(' × %s ÷ %s (%s, D241-7 IV du code de la sécurité sociale)', PayslipUnit::Euros->format($presence['due']), PayslipUnit::Euros->format($presence['full']), $presence['text']);
        }
        if (!$premiumHours->isZero()) {
            $text .= \sprintf(' + %s × %s (heures supplémentaires et complémentaires)', PayslipUnit::EurosPerHour->format($hourly), PayslipUnit::Hours->format($premiumHours));
        }

        return $text.' = '.FrenchFormat::amountInEuros($smic).' ('.$this->regulatory->text($this->parametersKey(), 'smic_reference').').';
    }

    /** The reduction of the month, or null when the remuneration reaches the exit threshold (or is nil). */
    public function calculate(Money $remuneration, Money $smic, BigDecimal $headcount): ?GeneralReduction
    {
        $key = $this->parametersKey();
        $threshold = $this->regulatory->decimal($key, 'exit_threshold_smic');
        if (!$remuneration->isPositive() || !$smic->isPositive() || $remuneration->toDecimal()->isGreaterThanOrEqualTo($threshold->multipliedBy($smic->toDecimal()))) {
            return null;
        }
        $band = HeadcountCondition::Below50->holds($headcount) ? 'lt_50' : 'ge_50';
        $calculated = $this->coefficient($remuneration->toDecimal(), $smic->toDecimal(), $band);
        $coefficient = $calculated['raw']->toScale($this->regulatory->integer($key, 'coefficient_decimals'), RoundingMode::HalfUp);
        $steps = $calculated['steps'];
        $steps[] = \sprintf('Arrondi à %d décimales : %s.', $this->regulatory->integer($key, 'coefficient_decimals'), PayslipUnit::Coefficient->format($coefficient));
        if ($coefficient->isGreaterThan($calculated['max'])) {
            $coefficient = $calculated['max'];
            $steps[] = 'Au plus le coefficient maximal : '.PayslipUnit::Coefficient->format($coefficient).'.';
        }
        $amount = Money::euros(PayslipUnit::Coefficient->times($remuneration->toDecimal(), $coefficient));
        $steps[] = \sprintf('Réduction : %s × %s = %s.', PayslipUnit::Euros->format($remuneration->toDecimal()), PayslipUnit::Coefficient->format($coefficient), FrenchFormat::amountInEuros($amount));

        $imputation = $this->impute($amount, $calculated['max']);
        $steps[] = $imputation['step'];

        return new GeneralReduction(static::regime(), $remuneration, $smic, $coefficient, $amount, $imputation['urssaf'], $imputation['retirement'], $steps, PayslipSource::of($this->regulatory, $key, null), $remuneration, $smic, $amount);
    }

    /**
     * The reduction of the month, regularised over the year: calculated on the year's remuneration and SMIC
     * of reference (this month included), less the reduction already made; negative when the year's
     * reduction falls (a reintegration). Null when the year has none and none was made.
     */
    public function regularised(Money $remuneration, Money $smic, PayslipYearToDate $before, BigDecimal $headcount): ?GeneralReduction
    {
        if ($before->isEmpty() && $before->generalReduction->isZero()) {
            return $this->calculate($remuneration, $smic, $headcount);
        }
        $yearRemuneration = $before->gross->plus($remuneration);
        $yearSmic = $before->smic->plus($smic);
        $already = $before->generalReduction;
        $year = $this->calculate($yearRemuneration, $yearSmic, $headcount);
        if (null === $year && $already->isZero()) {
            return null;
        }
        $band = HeadcountCondition::Below50->holds($headcount) ? 'lt_50' : 'ge_50';
        $coefficient = $year->coefficient ?? BigDecimal::zero();
        $yearAmount = $year->amount ?? Money::zero();
        $amount = $yearAmount->minus($already);
        $euros = PayslipUnit::Euros;
        $steps = [\sprintf('Régularisation progressive : rémunération de l’année %s (%s avant ce mois + %s), SMIC de référence de l’année %s (%s + %s).', $euros->format($yearRemuneration->toDecimal()), $euros->format($before->gross->toDecimal()), $euros->format($remuneration->toDecimal()), $euros->format($yearSmic->toDecimal()), $euros->format($before->smic->toDecimal()), $euros->format($smic->toDecimal()))];
        if (null === $year) {
            $steps[] = \sprintf('La rémunération de l’année atteint %s SMIC : pas de réduction pour l’année.', $this->number($this->regulatory->decimal($this->parametersKey(), 'exit_threshold_smic')));
        } else {
            // The year's own calculation, without its imputation: the month's amount is imputed below.
            array_push($steps, ...\array_slice($year->steps, 0, -1));
        }
        $steps[] = \sprintf('Réduction du mois : %s pour l’année − %s déjà faite = %s%s.', FrenchFormat::amountInEuros($yearAmount), FrenchFormat::amountInEuros($already), FrenchFormat::amountInEuros($amount), $amount->isNegative() ? ', réintégrée' : '');
        $imputation = $this->impute($amount, $this->maximum($band));
        $steps[] = $imputation['step'];

        return new GeneralReduction(static::regime(), $remuneration, $smic, $coefficient, $amount, $imputation['urssaf'], $imputation['retirement'], $steps, PayslipSource::of($this->regulatory, $this->parametersKey(), null), $yearRemuneration, $yearSmic, $yearAmount);
    }

    /**
     * The imputation of an amount: on the Urssaf by the share of its rates in the maximum, rounded to the
     * cent; the rest on the Agirc-Arrco.
     *
     * @return array{urssaf: Money, retirement: Money, step: string}
     */
    private function impute(Money $amount, BigDecimal $max): array
    {
        $retirementRate = $this->regulatory->decimal($this->coveredContributionsKey(), 'agirc_arrco')->dividedBy(100, self::SCALE);
        $urssaf = Money::euros($amount->toDecimal()->multipliedBy($max->minus($retirementRate))->dividedBy($max, 2, RoundingMode::HalfUp));
        $retirement = $amount->minus($urssaf);

        return ['urssaf' => $urssaf, 'retirement' => $retirement, 'step' => \sprintf('Imputation : Urssaf %s × (%s − %s) ÷ %s = %s ; retraite complémentaire, le reste : %s.', FrenchFormat::amountInEuros($amount), PayslipUnit::Coefficient->format($max), PayslipUnit::Coefficient->format($retirementRate->strippedOfTrailingZeros()), PayslipUnit::Coefficient->format($max), FrenchFormat::amountInEuros($urssaf), FrenchFormat::amountInEuros($retirement))];
    }

    abstract public static function regime(): string;

    protected function number(BigDecimal $value): string
    {
        return FrenchFormat::number($value->strippedOfTrailingZeros());
    }
}
