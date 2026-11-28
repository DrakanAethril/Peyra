<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * The four families of the plan de paie (lot 4, step 4.1), in the order a payslip reads them: what
 * makes the gross pay, the contributions on it, what is paid outside the social base (« non
 * soumis »: expenses refunded), and what is taken from the net (« retenues »: an advance, the
 * benefits in kind already received, the withholding tax).
 */
enum PayrollItemKind: string
{
    case Gross = 'gross';
    case Contribution = 'contribution';
    case NotSubject = 'not_subject';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Gross => 'Élément de brut',
            self::Contribution => 'Cotisation',
            self::NotSubject => 'Non soumis',
            self::Deduction => 'Retenue',
        };
    }

    /** The heading of the family in the plan's list. */
    public function heading(): string
    {
        return match ($this) {
            self::Gross => 'Éléments de brut',
            self::Contribution => 'Cotisations et contributions',
            self::NotSubject => 'Éléments non soumis',
            self::Deduction => 'Retenues sur le net',
        };
    }

    /** Only a contribution has an employer's side, a collecting body and a line of the clarified payslip. */
    public function isContribution(): bool
    {
        return self::Contribution === $this;
    }

    /** @return list<PayrollBase> the bases an item of the family may stand on */
    public function bases(): array
    {
        return match ($this) {
            self::Gross => [PayrollBase::ContractHours, PayrollBase::EnteredHours, PayrollBase::EnteredCount, PayrollBase::EnteredAmount, PayrollBase::BaseSalary],
            self::Contribution => [PayrollBase::Gross, PayrollBase::CsgBase, PayrollBase::MonthlyCeiling, PayrollBase::Flat, PayrollBase::OvertimePay, PayrollBase::OvertimeHours],
            self::NotSubject => [PayrollBase::EnteredCount, PayrollBase::EnteredAmount],
            self::Deduction => [PayrollBase::EnteredAmount, PayrollBase::BenefitsInKind, PayrollBase::NetTaxable],
        };
    }
}
