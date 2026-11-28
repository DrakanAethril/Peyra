<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * The « assiette » of an item of the plan de paie: the quantity its rate multiplies. The payslip
 * engine (step 4.3) reads each one; the plan only names it. A flat item (« forfait », the health
 * insurance of a company contract) has no base: its rate is an amount. The paid leave of the month
 * stands on two: its hours, taken off the salary, and its pay - the better of the salary kept
 * (« maintien ») and the tenth of the reference period's gross pay, the item's rate being the tenth.
 * The forfait social on the provident schemes stands on the employer's share of the contributions of
 * the payslip's « complémentaire santé » and « prévoyance » lines. Step 4.3d adds the days of the
 * month outside the contract (an employee hired or leaving during the month), their rate the salary
 * over the days of the month, and the sick pay: the hours whose salary is kept, at the hourly rate
 * times the item's rate, less the daily allowances of the social security entered beside them.
 * Step 4.3e adds the end of a contract: the end-of-contract indemnity of a fixed-term contract (the
 * contract's gross pay times the item's rate), the compensatory paid leave indemnity (the days left, as
 * the paid leave), the legal dismissal indemnity and the conventional termination indemnity (items not
 * subject, their exempt regime read by PayslipSeverance), the part of either subject to the CSG and the
 * CRDS, and the part of a conventional termination's exempt from contributions, which the employer's
 * specific contribution stands on.
 */
enum PayrollBase: string
{
    case ContractHours = 'contract_hours';
    case EnteredHours = 'entered_hours';
    case EnteredCount = 'entered_count';
    case EnteredAmount = 'entered_amount';
    case PaidLeaveHours = 'paid_leave_hours';
    case PaidLeavePay = 'paid_leave_pay';
    case BaseSalary = 'base_salary';
    case Gross = 'gross';
    case CsgBase = 'csg_base';
    case MonthlyCeiling = 'monthly_ceiling';
    case Flat = 'flat';
    case OvertimePay = 'overtime_pay';
    case OvertimeHours = 'overtime_hours';
    case ProvidentEmployer = 'provident_employer';
    case BenefitsInKind = 'benefits_in_kind';
    case NetTaxable = 'net_taxable';
    case EntryExitDays = 'entry_exit_days';
    case SickPay = 'sick_pay';
    case FixedTermEnd = 'fixed_term_end';
    case LeaveCompensation = 'leave_compensation';
    case Dismissal = 'dismissal';
    case ConventionalBreak = 'conventional_break';
    case SeveranceCsg = 'severance_csg';
    case ConventionalExempt = 'conventional_exempt';

    public function label(): string
    {
        return match ($this) {
            self::ContractHours => 'Horaire du contrat',
            self::EnteredHours => 'Heures du mois',
            self::EnteredCount => 'Nombre du mois',
            self::EnteredAmount => 'Montant du mois',
            self::PaidLeaveHours => 'Heures de congés payés',
            self::PaidLeavePay => 'Congés payés : maintien ou dixième',
            self::BaseSalary => 'Salaire de base',
            self::Gross => 'Salaire brut',
            self::CsgBase => 'Assiette CSG et CRDS',
            self::MonthlyCeiling => 'Plafond mensuel',
            self::Flat => 'Forfait (sans base)',
            self::OvertimePay => 'Rémunération des heures sup.',
            self::OvertimeHours => 'Heures supplémentaires',
            self::ProvidentEmployer => 'Part patronale prévoyance et santé',
            self::BenefitsInKind => 'Avantages en nature',
            self::NetTaxable => 'Net imposable',
            self::EntryExitDays => 'Jours hors contrat du mois',
            self::SickPay => 'Maintien maladie : heures, IJSS déduites',
            self::FixedTermEnd => 'Brut du CDD (fin de contrat)',
            self::LeaveCompensation => 'Congés payés non pris : maintien ou dixième',
            self::Dismissal => 'Indemnité légale de licenciement',
            self::ConventionalBreak => 'Indemnité de rupture conventionnelle',
            self::SeveranceCsg => 'Indemnité de rupture soumise à CSG',
            self::ConventionalExempt => 'Rupture conventionnelle exonérée',
        };
    }

    /** Only the gross salary is cut into brackets of the ceiling (tranche 1, tranche 2…). */
    public function takesCeiling(): bool
    {
        return self::Gross === $this;
    }
}
