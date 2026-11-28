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
 * (2026) - each reading its own parameters. Calculated on the month (« calcul mensuel anticipé »):
 * the regularisation over the year is step 4.3c's.
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

    /** The SMIC of reference of the month (see the class). */
    public function smic(BigDecimal $contractHours, BigDecimal $collectiveHours, BigDecimal $premiumHours): Money
    {
        $monthly = $this->regulatory->decimal($this->parametersKey(), 'smic_monthly_reference');
        $hourly = $this->regulatory->decimal($this->parametersKey(), 'smic_hourly_reference');
        $prorated = Money::euros($monthly->multipliedBy($contractHours)->dividedBy($collectiveHours, 2, RoundingMode::HalfUp));

        return $prorated->plus(Money::euros(PayslipUnit::EurosPerHour->times($premiumHours, $hourly)));
    }

    /** The explanation of the SMIC of reference, for the line. */
    public function smicExplanation(BigDecimal $contractHours, BigDecimal $collectiveHours, BigDecimal $premiumHours, Money $smic): string
    {
        $monthly = $this->regulatory->decimal($this->parametersKey(), 'smic_monthly_reference');
        $hourly = $this->regulatory->decimal($this->parametersKey(), 'smic_hourly_reference');
        $text = \sprintf('SMIC de référence du mois : %s', PayslipUnit::Euros->format($monthly));
        if (!$contractHours->isEqualTo($collectiveHours)) {
            $text .= \sprintf(' × %s ÷ %s', PayslipUnit::Hours->format($contractHours), PayslipUnit::Hours->format($collectiveHours));
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

        $retirementRate = $this->regulatory->decimal($this->coveredContributionsKey(), 'agirc_arrco')->dividedBy(100, self::SCALE);
        $max = $calculated['max'];
        $urssaf = Money::euros($amount->toDecimal()->multipliedBy($max->minus($retirementRate))->dividedBy($max, 2, RoundingMode::HalfUp));
        $retirement = $amount->minus($urssaf);
        $steps[] = \sprintf('Imputation : Urssaf %s × (%s − %s) ÷ %s = %s ; retraite complémentaire, le reste : %s.', FrenchFormat::amountInEuros($amount), PayslipUnit::Coefficient->format($max), PayslipUnit::Coefficient->format($retirementRate->strippedOfTrailingZeros()), PayslipUnit::Coefficient->format($max), FrenchFormat::amountInEuros($urssaf), FrenchFormat::amountInEuros($retirement));

        return new GeneralReduction(static::regime(), $remuneration, $smic, $coefficient, $amount, $urssaf, $retirement, $steps, PayslipSource::of($this->regulatory, $key, null));
    }

    abstract public static function regime(): string;

    protected function number(BigDecimal $value): string
    {
        return FrenchFormat::number($value->strippedOfTrailingZeros());
    }
}
