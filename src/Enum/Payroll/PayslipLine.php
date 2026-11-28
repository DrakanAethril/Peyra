<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * A line of the clarified payslip (arrêté du 25 février 2016, modèle clarifié): the contributions of
 * the plan de paie, detailed one by one, are summed under the line they belong to - « Autres
 * contributions dues par l’employeur » gathers the CSA, the FNAL, the social dialogue, the
 * vocational training, the apprenticeship tax and the mobility payment, as maquette 4 shows.
 */
enum PayslipLine: string
{
    case SocialSecurityHealth = 'social_security_health';
    case ComplementaryProvident = 'complementary_provident';
    case ComplementaryHealth = 'complementary_health';
    case WorkAccident = 'work_accident';
    case SocialSecurityCapped = 'social_security_capped';
    case SocialSecurityUncapped = 'social_security_uncapped';
    case ComplementaryTranche1 = 'complementary_tranche_1';
    case ComplementaryTranche2 = 'complementary_tranche_2';
    case Family = 'family';
    case Unemployment = 'unemployment';
    case Apec = 'apec';
    case OtherEmployerContributions = 'other_employer_contributions';
    case CsgDeductible = 'csg_deductible';
    case CsgCrdsNonDeductible = 'csg_crds_non_deductible';
    case Reductions = 'reductions';

    public function label(): string
    {
        return match ($this) {
            self::SocialSecurityHealth => 'Sécurité sociale : maladie, maternité, invalidité, décès',
            self::ComplementaryProvident => 'Complémentaire incapacité, invalidité, décès',
            self::ComplementaryHealth => 'Complémentaire santé',
            self::WorkAccident => 'Accidents du travail',
            self::SocialSecurityCapped => 'Sécurité sociale plafonnée',
            self::SocialSecurityUncapped => 'Sécurité sociale déplafonnée',
            self::ComplementaryTranche1 => 'Complémentaire tranche 1',
            self::ComplementaryTranche2 => 'Complémentaire tranche 2',
            self::Family => 'Famille',
            self::Unemployment => 'Assurance chômage',
            self::Apec => 'Apec',
            self::OtherEmployerContributions => 'Autres contributions dues par l’employeur',
            self::CsgDeductible => 'CSG déductible de l’impôt sur le revenu',
            self::CsgCrdsNonDeductible => 'CSG et CRDS non déductibles',
            self::Reductions => 'Exonérations et allègements de cotisations',
        };
    }

    public function section(): PayslipSection
    {
        return match ($this) {
            self::SocialSecurityHealth, self::ComplementaryProvident, self::ComplementaryHealth => PayslipSection::Health,
            self::WorkAccident => PayslipSection::WorkAccident,
            self::SocialSecurityCapped, self::SocialSecurityUncapped, self::ComplementaryTranche1, self::ComplementaryTranche2 => PayslipSection::Retirement,
            self::Family, self::Unemployment, self::Apec, self::OtherEmployerContributions => PayslipSection::FamilyUnemploymentOther,
            self::CsgDeductible, self::CsgCrdsNonDeductible => PayslipSection::Csg,
            self::Reductions => PayslipSection::Reductions,
        };
    }
}
