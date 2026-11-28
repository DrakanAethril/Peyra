<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayslipUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The general reduction of 2025 (article D241-7 of the code de la sécurité sociale, version of
 * 7 April 2025): C = T ÷ 0,6 × (1,6 × SMIC ÷ remuneration − 1), at most T, nothing from 1,6 SMIC.
 * The 0,6 is the threshold less one, read with it; T changes on 1 May 2025 (décret 2025-318), a
 * dated value.
 */
final class GeneralReductionRgcp extends GeneralReductionFormula
{
    public const string REGIME = 'rgcp';

    public static function regime(): string
    {
        return self::REGIME;
    }

    protected function parametersKey(): string
    {
        return 'social.general_reduction.parameters';
    }

    protected function coveredContributionsKey(): string
    {
        return 'social.general_reduction.covered_contributions';
    }

    protected function coefficient(BigDecimal $remuneration, BigDecimal $smic, string $band): array
    {
        $key = $this->parametersKey();
        $t = $this->regulatory->decimal($key, 't', $band);
        $threshold = $this->regulatory->decimal($key, 'exit_threshold_smic');
        $ratio = $threshold->multipliedBy($smic)->dividedBy($remuneration, self::SCALE, RoundingMode::Down)->minus(1);
        $raw = $t->multipliedBy($ratio)->dividedBy($threshold->minus(1), self::SCALE, RoundingMode::Down);

        return ['raw' => $raw, 'max' => $t, 'steps' => [
            \sprintf('Formule 2025 (article D241-7) : C = T ÷ (%1$s − 1) × (%1$s × SMIC ÷ rémunération − 1), nulle à partir de %1$s SMIC.', $this->number($threshold)),
            \sprintf('T (%s) : %s.', 'lt_50' === $band ? 'moins de 50 salariés' : '50 salariés et plus', PayslipUnit::Coefficient->format($t)),
            \sprintf('C = %s ÷ %s × (%s × %s ÷ %s − 1) = %s.', PayslipUnit::Coefficient->format($t), $this->number($threshold->minus(1)), $this->number($threshold), PayslipUnit::Euros->format($smic), PayslipUnit::Euros->format($remuneration), $this->number($raw->toScale(6, RoundingMode::HalfUp))),
        ]];
    }
}
