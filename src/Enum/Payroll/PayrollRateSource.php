<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * Where a rate of an item of the plan de paie comes from. **No rate is written in the plan when the
 * law fixes it**: it names a regulatory value (key and path), read at the payslip's date. The
 * dossier holds only what belongs to the company - a contract's rate or amount (health insurance,
 * provident fund), the work accident and mobility rates of its establishment - and the payslip
 * engine (step 4.3) brings what belongs to the employee or the month.
 */
enum PayrollRateSource: string
{
    case None = 'none';
    case Regulatory = 'regulatory';
    case Fixed = 'fixed';
    case Amount = 'amount';
    case WorkAccident = 'work_accident';
    case Mobility = 'mobility';
    case HourlyRate = 'hourly_rate';
    case HourlyRateWithPremium = 'hourly_rate_premium';
    case Entered = 'entered';
    case WithholdingRate = 'withholding_rate';
    case GeneralReduction = 'general_reduction';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Aucun',
            self::Regulatory => 'Valeur réglementaire',
            self::Fixed => 'Taux du dossier',
            self::Amount => 'Montant du dossier',
            self::WorkAccident => 'Taux d’accident du travail de l’établissement',
            self::Mobility => 'Taux de versement mobilité de l’établissement',
            self::HourlyRate => 'Taux horaire du contrat',
            self::HourlyRateWithPremium => 'Taux horaire majoré (majoration réglementaire)',
            self::Entered => 'Taux saisi dans le mois',
            self::WithholdingRate => 'Taux de prélèvement à la source du salarié',
            self::GeneralReduction => 'Coefficient de la réduction générale du millésime',
        };
    }

    /** It names a regulatory value: the rate itself, or the premium of an hourly rate. */
    public function needsReference(): bool
    {
        return self::Regulatory === $this || self::HourlyRateWithPremium === $this;
    }

    public function needsPercent(): bool
    {
        return self::Fixed === $this;
    }

    public function needsAmount(): bool
    {
        return self::Amount === $this;
    }

    /** The sources an item may use for its employee's side (« Taux », for what is not a contribution). */
    public function allowedOnEmployeeSide(PayrollItemKind $kind, PayrollBase $base): bool
    {
        if (self::None === $this) {
            return true;
        }

        return match ($kind) {
            PayrollItemKind::Gross => match ($base) {
                PayrollBase::ContractHours, PayrollBase::EnteredHours, PayrollBase::PaidLeaveHours => \in_array($this, [self::HourlyRate, self::HourlyRateWithPremium, self::Fixed], true),
                PayrollBase::PaidLeavePay, PayrollBase::SickPay, PayrollBase::FixedTermEnd, PayrollBase::LeaveCompensation => \in_array($this, [self::Regulatory, self::Fixed], true),
                PayrollBase::BaseSalary => \in_array($this, [self::Entered, self::Fixed, self::Regulatory], true),
                PayrollBase::EnteredCount => \in_array($this, [self::Regulatory, self::Amount], true),
                default => false,
            },
            PayrollItemKind::Contribution => match ($base) {
                PayrollBase::Flat => self::Amount === $this,
                default => \in_array($this, [self::Regulatory, self::Fixed], true),
            },
            PayrollItemKind::NotSubject => PayrollBase::EnteredCount === $base && \in_array($this, [self::Regulatory, self::Amount], true),
            PayrollItemKind::Deduction => PayrollBase::NetTaxable === $base && self::WithholdingRate === $this,
        };
    }

    /** The sources of an employer's share: a contribution's only. */
    public function allowedOnEmployerSide(PayrollItemKind $kind, PayrollBase $base): bool
    {
        if (self::None === $this) {
            return true;
        }
        if (!$kind->isContribution()) {
            return false;
        }

        return match ($base) {
            PayrollBase::Flat => self::Amount === $this,
            PayrollBase::Gross => \in_array($this, [self::Regulatory, self::Fixed, self::WorkAccident, self::Mobility, self::GeneralReduction], true),
            default => \in_array($this, [self::Regulatory, self::Fixed], true),
        };
    }
}
