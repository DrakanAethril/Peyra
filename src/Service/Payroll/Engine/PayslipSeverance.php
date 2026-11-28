<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\ExitReason;
use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * The indemnity of a dismissal or of a conventional termination (lot 4, step 4.3e), pure: its amount and
 * how much of it the law exempts, read on the last payslip of the contract.
 *
 * - **The legal dismissal indemnity** (service-public F987, R1234-2 of the code du travail,
 *   `social.severance.dismissal_legal`): the reference salary times a quarter of a month a year up to ten
 *   years and a third beyond, the complete months of an incomplete year in proportion, rounded once to the
 *   cent; nothing under eight months. The seniority runs from the day of hire to the day after the last day
 *   (the notice being part of the contract). A conventional termination's indemnity is the amount agreed,
 *   never below that legal amount.
 * - **Income tax** (F408, CGI art. 80 duodecies, `social.severance.exemption`): the legal amount is exempt; above
 *   it, within the greater of twice the previous year's gross pay and half the indemnity, at most six PASS.
 * - **Contributions**: exempt within that limit and within two PASS, nothing exempt above ten PASS. **The
 *   engine pays only an indemnity wholly exempt from contributions**: a part subject to them would join the
 *   gross pay, which is not built (A-VERIFIER, step 4.7) - it refuses rather than guess.
 * - **CSG and CRDS**, without abatement, on what exceeds the smaller of the legal amount and the part exempt
 *   from contributions; **the employer's specific contribution** of a conventional termination on the part
 *   exempt from contributions (`social.contrib.conventional_termination`).
 */
final readonly class PayslipSeverance
{
    public const string LEGAL_KEY = 'social.severance.dismissal_legal';
    public const string EXEMPTION_KEY = 'social.severance.exemption';

    /** @param list<string> $notes */
    private function __construct(
        public PayrollBase $base,
        public Money $legal,
        public Money $amount,
        public Money $taxExempt,
        public Money $socialExempt,
        public Money $csgBase,
        public string $legalText,
        public array $notes,
    ) {
    }

    /**
     * The indemnity of the payslip, null when there is none: the contract ends this month by a dismissal or
     * a conventional termination, and the month gives what it is calculated on.
     */
    public static function of(PayslipInput $input): ?self
    {
        $employee = $input->employee;
        $base = match ($employee->exitReason) {
            ExitReason::PersonalDismissal, ExitReason::EconomicDismissal => PayrollBase::Dismissal,
            ExitReason::ConventionalTermination => PayrollBase::ConventionalBreak,
            default => null,
        };
        $severance = $input->variables->severance;
        if (null === $base || null === $severance || !self::endsThisMonth($input)) {
            return null;
        }
        $regulatory = $input->regulatory;
        [$legal, $legalText] = self::legal($input, $severance);
        $amount = $legal;
        $notes = [];
        if (PayrollBase::ConventionalBreak === $base && null !== $severance->agreed) {
            if ($severance->agreed->compareTo($legal) < 0) {
                throw new \InvalidArgumentException(\sprintf('L’indemnité de rupture conventionnelle (%s) ne peut être inférieure à l’indemnité légale de licenciement (%s).', FrenchFormat::amountInEuros($severance->agreed), FrenchFormat::amountInEuros($legal)));
            }
            $amount = $severance->agreed;
        }
        if ($amount->isZero()) {
            return null;
        }

        $pass = $regulatory->decimal('social.pss_annual');
        $times = static fn (string $leaf) => $regulatory->decimal(self::EXEMPTION_KEY, $leaf)->multipliedBy($pass);
        if ($amount->compareTo($legal) <= 0) {
            $taxExempt = $amount;
            $notes[] = 'Impôt sur le revenu : le montant légal est exonéré en totalité.';
        } else {
            $previous = $severance->previousYearGross ?? throw new \InvalidArgumentException('Au-delà du montant légal, l’exonération d’impôt lit la rémunération brute de l’année civile précédant la rupture : saisissez-la.');
            $double = $previous->toDecimal()->multipliedBy($regulatory->decimal(self::EXEMPTION_KEY, 'tax_remuneration_times'));
            $half = $amount->toDecimal()->multipliedBy($regulatory->decimal(self::EXEMPTION_KEY, 'tax_indemnity_percent'))->dividedBy(100, 2, RoundingMode::HalfUp);
            $limit = Money::euros($double->isGreaterThan($half) ? $double : $half);
            $cap = Money::euros($times('tax_pss'));
            $limit = $limit->compareTo($cap) > 0 ? $cap : $limit;
            $limit = $limit->compareTo($legal) < 0 ? $legal : $limit;
            $taxExempt = $limit->compareTo($amount) < 0 ? $limit : $amount;
            $notes[] = \sprintf('Impôt sur le revenu : au-delà du montant légal, exonération dans la limite du plus grand de %s × la rémunération brute de l’année précédente (%s) et de %s de l’indemnité (%s), au plus %s PASS (%s) : %s exonérés.', FrenchFormat::number($regulatory->decimal(self::EXEMPTION_KEY, 'tax_remuneration_times')), FrenchFormat::amountInEuros($previous), PayslipUnit::Percent->format($regulatory->decimal(self::EXEMPTION_KEY, 'tax_indemnity_percent')), FrenchFormat::amountInEuros(Money::euros($half)), FrenchFormat::number($regulatory->decimal(self::EXEMPTION_KEY, 'tax_pss')), FrenchFormat::amountInEuros($cap), FrenchFormat::amountInEuros($taxExempt));
        }
        $fully = Money::euros($times('fully_subject_pss'));
        $social = Money::euros($times('social_pss'));
        $socialExempt = $amount->compareTo($fully) > 0 ? Money::zero() : ($taxExempt->compareTo($social) < 0 ? $taxExempt : $social);
        if ($socialExempt->compareTo($amount) < 0) {
            throw new \LogicException(\sprintf('Une part de l’indemnité de rupture (%s sur %s) est soumise à cotisations : le moteur de bulletin ne la calcule pas encore.', FrenchFormat::amountInEuros($amount->minus($socialExempt)), FrenchFormat::amountInEuros($amount)));
        }
        $notes[] = \sprintf('Cotisations sociales : exonérée dans la limite de l’exonération d’impôt et de %s PASS (%s) : %s exonérés.', FrenchFormat::number($regulatory->decimal(self::EXEMPTION_KEY, 'social_pss')), FrenchFormat::amountInEuros($social), FrenchFormat::amountInEuros($socialExempt));
        $kept = $legal->compareTo($socialExempt) < 0 ? $legal : $socialExempt;
        $csgBase = $amount->minus($kept);
        $notes[] = $csgBase->isZero()
            ? 'CSG et CRDS : l’indemnité ne dépasse pas le montant légal, rien n’y est soumis.'
            : \sprintf('CSG et CRDS, sans abattement : sur ce qui dépasse le plus petit du montant légal et de la part exonérée de cotisations, %s − %s = %s.', FrenchFormat::amountInEuros($amount), FrenchFormat::amountInEuros($kept), FrenchFormat::amountInEuros($csgBase));

        return new self($base, $legal, $amount, $taxExempt, $socialExempt, $csgBase, $legalText, $notes);
    }

    /** The source of what the explanation reads. */
    public static function source(PayslipInput $input): PayslipSource
    {
        return PayslipSource::of($input->regulatory, self::EXEMPTION_KEY, null);
    }

    /** The contract's last day falls in the payslip's month. */
    public static function endsThisMonth(PayslipInput $input): bool
    {
        $leaves = $input->employee->leavesOn?->setTime(0, 0);
        $month = PayslipMonth::of($input);

        return null !== $leaves && $leaves >= $month->first && $leaves <= $month->last;
    }

    /**
     * The legal dismissal indemnity and how it was found.
     *
     * @return array{0: Money, 1: string}
     */
    private static function legal(PayslipInput $input, Severance $severance): array
    {
        $regulatory = $input->regulatory;
        $employee = $input->employee;
        $hired = $employee->hiredOn ?? throw new \LogicException('A severance indemnity needs the day of hire.');
        $last = $employee->leavesOn ?? throw new \LogicException('A severance indemnity needs the last day of the contract.');
        $span = $hired->setTime(0, 0)->diff($last->setTime(0, 0)->modify('+1 day'));
        $months = $span->y * 12 + $span->m;
        $seniority = \sprintf('%d an%s %d mois', $span->y, $span->y > 1 ? 's' : '', $span->m);
        $reference = $severance->referenceSalary;
        if ($months < $regulatory->integer(self::LEGAL_KEY, 'min_seniority_months')) {
            return [Money::zero(), \sprintf('Ancienneté du %s au %s : %s, moins de %d mois : pas d’indemnité légale.', FrenchFormat::date($hired), FrenchFormat::date($last), $seniority, $regulatory->integer(self::LEGAL_KEY, 'min_seniority_months'))];
        }
        $threshold = $regulatory->integer(self::LEGAL_KEY, 'threshold_years') * 12;
        $first = BigRational::ofFraction($regulatory->integer(self::LEGAL_KEY, 'first_numerator'), $regulatory->integer(self::LEGAL_KEY, 'first_denominator'));
        $beyond = BigRational::ofFraction($regulatory->integer(self::LEGAL_KEY, 'beyond_numerator'), $regulatory->integer(self::LEGAL_KEY, 'beyond_denominator'));
        $within = min($months, $threshold);
        $above = max(0, $months - $threshold);
        $total = $reference->toDecimal()->toBigRational()->multipliedBy($first->multipliedBy($within)->plus($beyond->multipliedBy($above))->dividedBy(12));
        $legal = Money::euros($total->toScale(2, RoundingMode::HalfUp));
        $fraction = static fn (BigRational $f): string => $f->getNumerator().'/'.$f->getDenominator();
        $text = \sprintf('%s × %s × %s ÷ 12', FrenchFormat::amountInEuros($reference), $fraction($first), FrenchFormat::number($within).' mois');
        if ($above > 0) {
            $text .= \sprintf(' + %s × %s × %s ÷ 12', FrenchFormat::amountInEuros($reference), $fraction($beyond), FrenchFormat::number($above).' mois');
        }

        return [$legal, \sprintf('Indemnité légale de licenciement : %s = %s (ancienneté du %s au lendemain du %s : %s ; %s de mois de salaire par année jusqu’à %d ans, %s au-delà, les mois complets au prorata).', $text, FrenchFormat::amountInEuros($legal), FrenchFormat::date($hired), FrenchFormat::date($last), $seniority, $fraction($first), $threshold / 12, $fraction($beyond))];
    }
}
