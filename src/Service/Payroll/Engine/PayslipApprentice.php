<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What the law spares an apprentice (lot 4, step 4.3e), pure (service-public F2918,
 * `social.apprentice.exemption`): a part of the salary, a share of the monthly SMIC of the payslip's day,
 * is exempt from the employee contributions the law sets - 50 % for a contract started on or after
 * 1 March 2025, 79 % before - and, for the newer contracts, from the CSG and the CRDS; the older ones pay
 * neither. In a month the contract does not cover whole, the share is prorated over the days of the
 * employment period, never over the working time nor an absence. The income tax exemption (within the
 * annual SMIC) is PayslipNet's.
 */
final readonly class PayslipApprentice
{
    public const string KEY = 'social.apprentice.exemption';
    public const string SMIC_KEY = 'social.smic_monthly';

    private function __construct(
        public bool $newRegime,
        public BigDecimal $share,
        public Money $exempt,
        public string $explanation,
        public PayslipSource $source,
    ) {
    }

    /** Null for an employee who is not an apprentice. */
    public static function of(PayslipInput $input): ?self
    {
        $employee = $input->employee;
        if (!$employee->isApprentice()) {
            return null;
        }
        $regulatory = $input->regulatory;
        $started = $employee->hiredOn ?? throw new \LogicException('An apprenticeship payslip needs the day the contract started: it says which exemption applies.');
        $from = new \DateTimeImmutable($regulatory->text(self::KEY, 'regime_from'));
        $new = $started->setTime(0, 0) >= $from;
        $share = $regulatory->decimal(self::KEY, $new ? 'smic_share' : 'former_smic_share');
        $smic = $regulatory->decimal(self::SMIC_KEY);
        $month = PayslipMonth::of($input);
        $exempt = $smic->multipliedBy($share)->multipliedBy($month->presentDays)->dividedBy($month->days * 100, 2, RoundingMode::HalfUp);
        $text = \sprintf(
            'Apprenti (contrat commencé le %s, %s le %s) : part exonérée %s × %s',
            FrenchFormat::date($started),
            $new ? 'à partir du' : 'avant le',
            FrenchFormat::date($from),
            PayslipUnit::Percent->format($share),
            FrenchFormat::amountInEuros(Money::euros($smic)).' (SMIC mensuel)',
        );
        $text .= $month->isComplete() ? '' : \sprintf(' × %d ÷ %d jours de la période d’emploi', $month->presentDays, $month->days);

        return new self($new, $share, Money::euros($exempt), $text.' = '.FrenchFormat::amountInEuros(Money::euros($exempt)).'.', PayslipSource::of($regulatory, self::KEY, null));
    }
}
