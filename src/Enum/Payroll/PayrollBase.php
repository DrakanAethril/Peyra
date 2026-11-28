<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * The « assiette » of an item of the plan de paie: the quantity its rate multiplies. The payslip
 * engine (step 4.3) reads each one; the plan only names it. A flat item (« forfait », the health
 * insurance of a company contract) has no base: its rate is an amount.
 */
enum PayrollBase: string
{
    case ContractHours = 'contract_hours';
    case EnteredHours = 'entered_hours';
    case EnteredCount = 'entered_count';
    case EnteredAmount = 'entered_amount';
    case BaseSalary = 'base_salary';
    case Gross = 'gross';
    case CsgBase = 'csg_base';
    case MonthlyCeiling = 'monthly_ceiling';
    case Flat = 'flat';
    case OvertimePay = 'overtime_pay';
    case OvertimeHours = 'overtime_hours';
    case BenefitsInKind = 'benefits_in_kind';
    case NetTaxable = 'net_taxable';

    public function label(): string
    {
        return match ($this) {
            self::ContractHours => 'Horaire du contrat',
            self::EnteredHours => 'Heures du mois',
            self::EnteredCount => 'Nombre du mois',
            self::EnteredAmount => 'Montant du mois',
            self::BaseSalary => 'Salaire de base',
            self::Gross => 'Salaire brut',
            self::CsgBase => 'Assiette CSG et CRDS',
            self::MonthlyCeiling => 'Plafond mensuel',
            self::Flat => 'Forfait (sans base)',
            self::OvertimePay => 'Rémunération des heures sup.',
            self::OvertimeHours => 'Heures supplémentaires',
            self::BenefitsInKind => 'Avantages en nature',
            self::NetTaxable => 'Net imposable',
        };
    }

    /** Only the gross salary is cut into brackets of the ceiling (tranche 1, tranche 2…). */
    public function takesCeiling(): bool
    {
        return self::Gross === $this;
    }
}
