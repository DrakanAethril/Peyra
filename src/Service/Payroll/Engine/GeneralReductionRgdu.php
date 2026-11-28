<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayslipUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The single degressive general reduction from 2026 (RGDU; art. 18 LFSS 2025, décrets 2025-887 and
 * 2025-1446): C = Tmin + Tdelta × [½ × (3 × SMIC ÷ remuneration − 1)]^P, at most Tmin + Tdelta,
 * nothing from 3 SMIC - so 2 % just below the threshold, nothing at it. The ½ is one over the
 * threshold less one, read with it; Tdelta depends on the headcount.
 */
final class GeneralReductionRgdu extends GeneralReductionFormula
{
    public const string REGIME = 'rgdu';

    public static function regime(): string
    {
        return self::REGIME;
    }

    protected function parametersKey(): string
    {
        return 'social.rgdu.parameters';
    }

    protected function coveredContributionsKey(): string
    {
        return 'social.rgdu.covered_contributions';
    }

    protected function coefficient(BigDecimal $remuneration, BigDecimal $smic, string $band): array
    {
        $key = $this->parametersKey();
        $tMin = $this->regulatory->decimal($key, 't_min');
        $tDelta = $this->regulatory->decimal($key, 't_delta', $band);
        $max = $this->regulatory->decimal($key, 'coefficient_max', $band);
        $exponent = $this->regulatory->decimal($key, 'exponent_p');
        $threshold = $this->regulatory->decimal($key, 'exit_threshold_smic');
        $bracket = $threshold->multipliedBy($smic)->dividedBy($remuneration, self::SCALE, RoundingMode::Down)->minus(1)
            ->dividedBy($threshold->minus(1), self::SCALE, RoundingMode::Down);
        $raised = DecimalPower::of($bracket, $exponent, self::SCALE);
        $raw = $tMin->plus($tDelta->multipliedBy($raised));

        return ['raw' => $raw, 'max' => $max, 'steps' => [
            \sprintf('Formule 2026 (réduction générale dégressive unique) : C = Tmin + Tdelta × [(%1$s × SMIC ÷ rémunération − 1) ÷ (%1$s − 1)]^P, nulle à partir de %1$s SMIC.', $this->number($threshold)),
            \sprintf('Tmin %s, Tdelta (%s) %s, P %s ; coefficient maximal %s.', PayslipUnit::Coefficient->format($tMin), 'lt_50' === $band ? 'moins de 50 salariés' : '50 salariés et plus', PayslipUnit::Coefficient->format($tDelta), $this->number($exponent), PayslipUnit::Coefficient->format($max)),
            \sprintf('Crochet : (%s × %s ÷ %s − 1) ÷ %s = %s ; à la puissance %s : %s.', $this->number($threshold), PayslipUnit::Euros->format($smic), PayslipUnit::Euros->format($remuneration), $this->number($threshold->minus(1)), $this->number($bracket->toScale(6, RoundingMode::HalfUp)), $this->number($exponent), $this->number($raised->toScale(6, RoundingMode::HalfUp))),
            \sprintf('C = %s + %s × %s = %s.', PayslipUnit::Coefficient->format($tMin), PayslipUnit::Coefficient->format($tDelta), $this->number($raised->toScale(6, RoundingMode::HalfUp)), $this->number($raw->toScale(6, RoundingMode::HalfUp))),
        ]];
    }
}
