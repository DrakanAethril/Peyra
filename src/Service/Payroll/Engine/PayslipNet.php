<?php

declare(strict_types=1);

namespace App\Service\Payroll\Engine;

use App\Enum\Payroll\PayrollBase;
use App\Enum\Payroll\PayrollItemKind;
use App\Enum\Payroll\PayrollRateSource;
use App\Enum\Payroll\PayslipLine;
use App\Enum\Payroll\PayslipUnit;
use App\Service\Payroll\Plan\PayrollItemSpec;
use App\Service\Shared\FrenchFormat;
use App\Service\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * From the contributions to the net paid (lot 4, step 4.3c), pure: the deductions from the net (the
 * benefits in kind received, an advance, the withholding tax), the net figures and the year to date
 * after the payslip.
 *
 * - **Montant net social** (CSS R844-1 II, since 2024): the gross pay less every employee contribution;
 *   the employer's share of the collective health and provident schemes stays out.
 * - **Net imposable**: the gross pay less the employee contributions, plus the CSG and CRDS that are not
 *   deductible and the employer's share of the complementary health (the provident share is not
 *   taxable, CGI art. 83), less the overtime exempt from income tax: its pay, less the employee
 *   contributions still due on it - the effective employee rate of the rubriques on the gross, less the
 *   reduction - and less its deductible CSG, within the year's limit
 *   (`social.overtime.income_tax_exemption`).
 * - **An apprentice's salary** (step 4.3e, CGI art. 81 bis, `social.apprentice.income_tax_exemption`) is exempt
 *   from income tax within the annual SMIC, the limit used up over the year (PayslipYearToDate): the net
 *   taxable loses what is exempt this month.
 * - **Prélèvement à la source**: the net taxable times the rate the tax office sent, or the neutral
 *   rate of the grid (WithholdingTax), rounded half up to the cent.
 * - **Net à payer avant impôt**: the gross pay less the employee contributions, plus the items not
 *   subject, less the deductions other than the tax; **net payé**: that less the tax.
 * - **Coût employeur**: the gross pay plus the employer's contributions, reductions deducted.
 */
final class PayslipNet
{
    public const string EXEMPTION_KEY = 'social.overtime.income_tax_exemption';
    public const string APPRENTICE_KEY = 'social.apprentice.income_tax_exemption';

    /**
     * @param list<PayrollItemSpec> $ordered the plan in its order
     * @param list<PayslipRow>      $rows    gross pay, contributions and items not subject
     *
     * @return array{rows: list<PayslipRow>, totals: PayslipTotals, yearToDate: PayslipYearToDate}
     */
    public static function calculate(PayslipInput $input, array $ordered, array $rows, Money $gross, BigDecimal $overtimePay, ?GeneralReduction $reduction, Money $smic, Money $ceiling): array
    {
        $euros = PayslipUnit::Euros;
        $employee = Money::zero();
        $employer = Money::zero();
        $notSubject = Money::zero();
        foreach ($rows as $row) {
            if (PayrollItemKind::Contribution === $row->kind) {
                $employee = $employee->plus($row->employeeAmount);
                $employer = $employer->plus($row->employerAmount ?? Money::zero());
            } elseif (PayrollItemKind::NotSubject === $row->kind) {
                $notSubject = $notSubject->plus($row->employeeAmount);
            }
        }
        $nonDeductible = self::lineTotal($rows, PayslipLine::CsgCrdsNonDeductible, false);
        $health = self::lineTotal($rows, PayslipLine::ComplementaryHealth, true);
        $exempt = self::exemptOvertime($input, $rows, $gross, $overtimePay);
        $netSocial = $gross->minus($employee);
        $netTaxable = $gross->minus($employee)->plus($nonDeductible)->plus($health)->minus($exempt['amount']);
        $apprentice = self::apprenticeExempt($input, $netTaxable);
        $netTaxable = $netTaxable->minus($apprentice['amount'] ?? Money::zero());
        $explanations = [
            'netSocial' => new PayslipExplanation(
                \sprintf('%s − %s = %s', FrenchFormat::amountInEuros($gross), FrenchFormat::amountInEuros($employee), FrenchFormat::amountInEuros($netSocial)),
                'Salaire brut moins toutes les cotisations et contributions salariales.',
                'Sans taux : la part patronale de la complémentaire santé et de la prévoyance n’y entre pas (article R. 844-1 du code de la sécurité sociale).',
                PayslipSource::of($input->regulatory, 'social.net_social_amount', null),
            ),
            'netTaxable' => new PayslipExplanation(
                \sprintf('%s − %s + %s + %s − %s = %s', FrenchFormat::amountInEuros($gross), FrenchFormat::amountInEuros($employee), FrenchFormat::amountInEuros($nonDeductible), FrenchFormat::amountInEuros($health), FrenchFormat::amountInEuros($exempt['amount']), FrenchFormat::amountInEuros($netTaxable)),
                'Salaire brut moins les cotisations salariales, plus la CSG et la CRDS non déductibles et la part patronale de la complémentaire santé, moins les heures supplémentaires exonérées'.(null === $apprentice ? '.' : \sprintf(' et le salaire d’apprenti exonéré (%s).', FrenchFormat::amountInEuros($apprentice['amount']))),
                'Sans taux : la part patronale de prévoyance n’est pas imposable (article 83 du code général des impôts).',
            ),
            'exemptOvertime' => $exempt['explanation'],
            ...(null === $apprentice ? [] : ['apprenticeExempt' => $apprentice['explanation']]),
            'employerCost' => new PayslipExplanation(
                \sprintf('%s + %s = %s', FrenchFormat::amountInEuros($gross), FrenchFormat::amountInEuros($employer), FrenchFormat::amountInEuros($gross->plus($employer))),
                'Salaire brut plus les cotisations patronales, réductions déduites.',
                'Sans taux.',
            ),
        ];

        $benefits = self::benefitsInKind($input, $rows);
        $deductions = [];
        $tax = null;
        foreach ($ordered as $spec) {
            if (PayrollItemKind::Deduction !== $spec->kind || !$input->adjustments->takes($spec, $input->employee->executive)) {
                continue;
            }
            $row = $input->adjustments->apply($spec, match ($spec->base) {
                PayrollBase::BenefitsInKind => self::benefitsRow($spec, $benefits),
                PayrollBase::EnteredAmount => self::amountRow($spec, $input),
                PayrollBase::NetTaxable => self::taxRow($spec, $input, $netTaxable),
                default => throw new \LogicException(\sprintf('Rubrique %s : une retenue ne repose pas sur « %s ».', $spec->code, $spec->base->label())),
            });
            if (PayrollBase::NetTaxable === $spec->base) {
                $tax = $row;
            }
            if (null !== $row) {
                $deductions[] = $row;
            }
        }

        $taken = Money::zero();
        foreach ($deductions as $row) {
            if ($row !== $tax) {
                $taken = $taken->plus($row->employeeAmount);
            }
        }
        $netBeforeTax = $gross->minus($employee)->plus($notSubject)->plus($taken);
        $withholding = null === $tax ? Money::zero() : $tax->employeeAmount->negated();
        $netPay = $netBeforeTax->minus($withholding);
        $explanations['netBeforeTax'] = new PayslipExplanation(
            \sprintf('%s − %s + %s − %s = %s', FrenchFormat::amountInEuros($gross), FrenchFormat::amountInEuros($employee), FrenchFormat::amountInEuros($notSubject), FrenchFormat::amountInEuros($taken->negated()), FrenchFormat::amountInEuros($netBeforeTax)),
            'Salaire brut moins les cotisations salariales, plus les éléments non soumis, moins les retenues autres que l’impôt (avantages en nature, acompte).',
            'Sans taux.',
        );
        $explanations['withholdingTax'] = null === $tax
            ? new PayslipExplanation('Aucun prélèvement : le plan de paie n’a pas de rubrique de prélèvement à la source.', 'Net imposable : '.FrenchFormat::amountInEuros($netTaxable).'.', 'Sans taux.')
            : $tax->explanation;
        $explanations['netPay'] = new PayslipExplanation(
            \sprintf('%s − %s = %s', FrenchFormat::amountInEuros($netBeforeTax), FrenchFormat::amountInEuros($withholding), FrenchFormat::amountInEuros($netPay)),
            'Net à payer avant impôt moins le prélèvement à la source.',
            'Sans taux.',
        );

        $before = $input->yearToDate;
        $yearToDate = new PayslipYearToDate(
            $before->gross->plus($gross),
            $before->ceiling->plus($ceiling),
            $before->smic->plus($smic),
            $before->generalReduction->plus($reduction->amount ?? Money::zero()),
            $before->exemptOvertime->plus($exempt['amount']),
            $before->netTaxable->plus($netTaxable),
            $before->apprenticeExempt->plus($apprentice['amount'] ?? Money::zero()),
        );

        return ['rows' => $deductions, 'totals' => new PayslipTotals(
            $employee,
            $employer,
            $netSocial,
            $netTaxable,
            $exempt['amount'],
            $netBeforeTax,
            $tax->employeeRate ?? BigDecimal::zero(),
            null !== $tax && null === $input->employee->withholdingRate,
            $withholding,
            $netPay,
            $gross->plus($employer),
            $explanations,
            $apprentice['amount'] ?? null,
        ), 'yearToDate' => $yearToDate];
    }

    /**
     * The shares of the rubriques summed under a line of the clarified payslip.
     *
     * @param list<PayslipRow> $rows
     */
    private static function lineTotal(array $rows, PayslipLine $line, bool $employer): Money
    {
        $total = Money::zero();
        foreach ($rows as $row) {
            if ($line === $row->line) {
                $total = $total->plus($employer ? ($row->employerAmount ?? Money::zero()) : $row->employeeAmount);
            }
        }

        return $total;
    }

    /**
     * The overtime exempt from income tax this month: its pay, less the employee contributions still due
     * on it (the effective employee rate of the rubriques on the gross pay times its pay, less the
     * reduction of the « exonérations » line) and its deductible CSG, within what the year leaves.
     *
     * @param list<PayslipRow> $rows
     *
     * @return array{amount: Money, explanation: PayslipExplanation}
     */
    private static function exemptOvertime(PayslipInput $input, array $rows, Money $gross, BigDecimal $overtimePay): array
    {
        $euros = PayslipUnit::Euros;
        $percent = PayslipUnit::Percent;
        $source = PayslipSource::of($input->regulatory, self::EXEMPTION_KEY, 'annual_limit');
        if (!$overtimePay->isPositive() || !$gross->isPositive()) {
            return ['amount' => Money::zero(), 'explanation' => new PayslipExplanation('Aucune heure supplémentaire ni complémentaire ce mois : rien d’exonéré.', 'Rémunération des heures supplémentaires et complémentaires : 0,00 €.', 'Sans taux.', $source)];
        }
        $weighted = BigDecimal::zero();
        $reduction = Money::zero();
        $csgRate = BigDecimal::zero();
        foreach ($rows as $row) {
            if (PayrollItemKind::Contribution !== $row->kind) {
                continue;
            }
            if (PayslipLine::Reductions === $row->line) {
                $reduction = $reduction->plus($row->employeeAmount);
            } elseif (PayslipLine::CsgDeductible === $row->line) {
                $csgRate = $csgRate->plus($row->employeeRate ?? BigDecimal::zero());
            } elseif (PayslipLine::CsgCrdsNonDeductible !== $row->line && PayslipUnit::Percent === $row->employeeRateUnit && null !== $row->employeeRate && null !== ($base = $row->employeeBaseOrBase())) {
                $weighted = $weighted->plus($row->employeeRate->multipliedBy($base));
            }
        }
        $onOvertime = Money::euros($overtimePay->multipliedBy($weighted)->dividedBy($gross->toDecimal()->multipliedBy(100), 2, RoundingMode::HalfUp));
        $kept = BigDecimal::of(100)->minus($input->regulatory->decimal('social.csg_abatement', 'rate'));
        $csg = Money::euros($overtimePay->multipliedBy($kept)->multipliedBy($csgRate)->dividedBy(10000, 2, RoundingMode::HalfUp));
        $net = Money::euros($overtimePay)->minus($onOvertime->plus($reduction))->minus($csg);
        if ($net->isNegative()) {
            $net = Money::zero();
        }
        $limit = Money::euros($input->regulatory->decimal(self::EXEMPTION_KEY, 'annual_limit'));
        $left = $limit->minus($input->yearToDate->exemptOvertime);
        if ($left->isNegative()) {
            $left = Money::zero();
        }
        $amount = $net->compareTo($left) > 0 ? $left : $net;
        $notes = [
            \sprintf('Cotisations salariales sur ces heures : %s × %s (taux effectif des rubriques sur le brut) = %s ; réduction %s ; restent %s.', $euros->format($overtimePay), $percent->format($weighted->dividedBy($gross->toDecimal(), 4, RoundingMode::HalfUp)), FrenchFormat::amountInEuros($onOvertime), FrenchFormat::amountInEuros($reduction), FrenchFormat::amountInEuros($onOvertime->plus($reduction))),
            \sprintf('CSG déductible sur ces heures : %s × %s × %s = %s.', $euros->format($overtimePay), $percent->format($kept), $percent->format($csgRate), FrenchFormat::amountInEuros($csg)),
            \sprintf('Plafond annuel %s ; déjà exonéré cette année %s ; reste %s.', FrenchFormat::amountInEuros($limit), FrenchFormat::amountInEuros($input->yearToDate->exemptOvertime), FrenchFormat::amountInEuros($left)),
        ];

        return ['amount' => $amount, 'explanation' => new PayslipExplanation(
            \sprintf('%s − %s − %s = %s%s', FrenchFormat::amountInEuros(Money::euros($overtimePay)), FrenchFormat::amountInEuros($onOvertime->plus($reduction)), FrenchFormat::amountInEuros($csg), FrenchFormat::amountInEuros($net), $amount->equals($net) ? '' : ', limité à '.FrenchFormat::amountInEuros($amount)),
            'Rémunération des heures supplémentaires et complémentaires du mois, majorations comprises : '.$euros->format($overtimePay).'.',
            'Exonération d’impôt sur le revenu dans la limite annuelle de '.FrenchFormat::amountInEuros($limit).' nets (article 81 quater du code général des impôts).',
            $source,
            $notes,
        )];
    }

    /**
     * An apprentice's salary exempt from income tax this month: the net taxable, within what the year's
     * limit leaves. Null for an employee who is not an apprentice.
     *
     * @return array{amount: Money, explanation: PayslipExplanation}|null
     */
    private static function apprenticeExempt(PayslipInput $input, Money $netTaxable): ?array
    {
        if (!$input->employee->isApprentice()) {
            return null;
        }
        $limit = Money::euros($input->regulatory->decimal(self::APPRENTICE_KEY, 'annual_limit'));
        $already = $input->yearToDate->apprenticeExempt;
        $left = $limit->minus($already);
        if ($left->isNegative()) {
            $left = Money::zero();
        }
        $taxable = $netTaxable->isNegative() ? Money::zero() : $netTaxable;
        $amount = $taxable->compareTo($left) > 0 ? $left : $taxable;

        return ['amount' => $amount, 'explanation' => new PayslipExplanation(
            \sprintf('Exonéré : %s%s', FrenchFormat::amountInEuros($amount), $amount->equals($taxable) ? ', tout le salaire imposable du mois' : \sprintf(', ce qui reste de la limite annuelle sur %s', FrenchFormat::amountInEuros($taxable))),
            'Net imposable du mois avant l’exonération : '.FrenchFormat::amountInEuros($taxable).'.',
            \sprintf('Salaire d’apprenti exonéré d’impôt sur le revenu dans la limite annuelle du SMIC (article 81 bis du code général des impôts) : %s ; déjà exonéré cette année %s ; reste %s.', FrenchFormat::amountInEuros($limit), FrenchFormat::amountInEuros($already), FrenchFormat::amountInEuros($left)),
            PayslipSource::of($input->regulatory, self::APPRENTICE_KEY, 'annual_limit'),
        )];
    }

    /**
     * The elements of gross pay received in kind this month.
     *
     * @param list<PayslipRow> $rows
     *
     * @return array{amount: Money, codes: list<string>}
     */
    private static function benefitsInKind(PayslipInput $input, array $rows): array
    {
        $marked = [];
        foreach ($input->plan as $spec) {
            if ($spec->benefitInKind && PayrollItemKind::Gross === $spec->kind) {
                $marked[$spec->code] = true;
            }
        }
        $amount = Money::zero();
        $codes = [];
        foreach ($rows as $row) {
            if (PayrollItemKind::Gross === $row->kind && isset($marked[$row->code])) {
                $amount = $amount->plus($row->employeeAmount);
                $codes[] = $row->code;
            }
        }

        return ['amount' => $amount, 'codes' => $codes];
    }

    /** @param array{amount: Money, codes: list<string>} $benefits */
    private static function benefitsRow(PayrollItemSpec $spec, array $benefits): ?PayslipRow
    {
        if ($benefits['amount']->isZero()) {
            return null;
        }

        return new PayslipRow($spec->code, $spec->label, $spec->kind, $benefits['amount']->toDecimal(), PayslipUnit::Euros, null, null, $benefits['amount']->negated(), new PayslipExplanation(
            'Avantages en nature du mois, retenus sur le net : '.FrenchFormat::amountInEuros($benefits['amount']),
            \sprintf('Rubriques de brut marquées avantage en nature (%s) : comptées dans le brut, elles ont été reçues en nature et ne sont pas versées.', implode(', ', $benefits['codes'])),
            'Sans taux : la retenue est leur montant.',
        ));
    }

    private static function amountRow(PayrollItemSpec $spec, PayslipInput $input): ?PayslipRow
    {
        $amount = $input->variables->amount($spec->code);
        if (null === $amount) {
            return null;
        }

        return new PayslipRow($spec->code, $spec->label, $spec->kind, null, null, null, null, $amount->negated(), new PayslipExplanation(
            'Montant saisi, retenu sur le net : '.FrenchFormat::amountInEuros($amount),
            'Montant saisi dans les éléments du mois.',
            'Sans taux : la rubrique est un montant.',
        ));
    }

    private static function taxRow(PayrollItemSpec $spec, PayslipInput $input, Money $netTaxable): PayslipRow
    {
        if (PayrollRateSource::WithholdingRate !== $spec->employeeRate->getSource()) {
            throw new \LogicException(\sprintf('Rubrique %s : le prélèvement à la source prend le taux du salarié.', $spec->code));
        }
        $percent = PayslipUnit::Percent;
        $sent = $input->employee->withholdingRate;
        $source = null;
        if (null !== $sent) {
            $rate = $sent;
            $origin = 'Taux transmis par l’administration fiscale, sur la fiche du salarié : '.$percent->format($rate).'.';
        } else {
            $neutral = WithholdingTax::neutralRate($input->regulatory, $netTaxable);
            $rate = $neutral['rate'];
            $source = PayslipSource::of($input->regulatory, WithholdingTax::GRID_KEY, null);
            $origin = \sprintf('Taux neutre (aucun taux transmis) : %s pour un net imposable %s, grille mensuelle en vigueur au %s.', $percent->format($rate), null === $neutral['to']
                ? 'à partir de '.PayslipUnit::Euros->format($neutral['from'])
                : \sprintf('de %s à moins de %s', PayslipUnit::Euros->format($neutral['from']), PayslipUnit::Euros->format($neutral['to'])), FrenchFormat::date($input->regulatory->day));
            if (!PayslipMonth::of($input)->isComplete()) {
                $origin .= ' Le mois est incomplet (entrée ou sortie) : la grille mensuelle s’applique telle quelle, sans proratisation.';
            }
        }
        $base = $netTaxable->toDecimal();
        $amount = $netTaxable->isPositive() ? Money::euros($percent->times($base, $rate)) : Money::zero();

        return new PayslipRow($spec->code, $spec->label, $spec->kind, $base, PayslipUnit::Euros, $rate, $percent, $amount->negated(), new PayslipExplanation(
            \sprintf('%s × %s = %s, retenu sur le net', PayslipUnit::Euros->format($base), $percent->format($rate), FrenchFormat::amountInEuros($amount)),
            'Net imposable du mois : '.FrenchFormat::amountInEuros($netTaxable).'.',
            $origin,
            $source,
        ));
    }
}
