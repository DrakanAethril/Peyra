<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/**
 * Why an employment contract ends (step 4.2c), the cases of the référentiel: an open-ended contract
 * ends by a resignation, a dismissal, a conventional termination or a retirement; a fixed-term
 * contract and an apprenticeship at their term or, early, by a termination; any contract during its
 * trial period. ExitRules reads which contract allows which reason.
 */
enum ExitReason: string
{
    case Resignation = 'resignation';
    case PersonalDismissal = 'personal_dismissal';
    case EconomicDismissal = 'economic_dismissal';
    case ConventionalTermination = 'conventional_termination';
    case Retirement = 'retirement';
    case FixedTermEnd = 'fixed_term_end';
    case ApprenticeshipEnd = 'apprenticeship_end';
    case EarlyTermination = 'early_termination';
    case TrialPeriod = 'trial_period';

    public function label(): string
    {
        return match ($this) {
            self::Resignation => 'Démission',
            self::PersonalDismissal => 'Licenciement pour motif personnel',
            self::EconomicDismissal => 'Licenciement pour motif économique',
            self::ConventionalTermination => 'Rupture conventionnelle',
            self::Retirement => 'Départ volontaire à la retraite',
            self::FixedTermEnd => 'Fin du contrat à durée déterminée',
            self::ApprenticeshipEnd => 'Fin du contrat d’apprentissage',
            self::EarlyTermination => 'Rupture anticipée du contrat',
            self::TrialPeriod => 'Rupture pendant la période d’essai',
        };
    }

    /** The contracts this reason ends. */
    public function allows(ContractType $type): bool
    {
        return match ($this) {
            self::Resignation, self::PersonalDismissal, self::EconomicDismissal, self::ConventionalTermination, self::Retirement => ContractType::Permanent === $type,
            self::FixedTermEnd => ContractType::FixedTerm === $type,
            self::ApprenticeshipEnd => ContractType::Apprenticeship === $type,
            self::EarlyTermination => ContractType::Permanent !== $type,
            self::TrialPeriod => true,
        };
    }

    /** A contract reaching its term ends on the day it names; every other reason is notified first (a letter, a convention). */
    public function isTerm(): bool
    {
        return self::FixedTermEnd === $this || self::ApprenticeshipEnd === $this;
    }

    /** What the notification day is, for the field's label. */
    public function notificationLabel(): string
    {
        return match ($this) {
            self::Resignation => 'Lettre de démission du',
            self::PersonalDismissal, self::EconomicDismissal => 'Lettre de licenciement notifiée le',
            self::ConventionalTermination => 'Convention signée le',
            self::Retirement => 'Départ annoncé le',
            self::FixedTermEnd, self::ApprenticeshipEnd, self::EarlyTermination, self::TrialPeriod => 'Rupture notifiée le',
        };
    }
}
