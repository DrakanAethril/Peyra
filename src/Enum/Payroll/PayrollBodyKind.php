<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

/** The kinds of collecting body (« organisme ») a contribution is paid to. */
enum PayrollBodyKind: string
{
    case Urssaf = 'urssaf';
    case Retirement = 'retirement';
    case Provident = 'provident';
    case Health = 'health';

    public function label(): string
    {
        return match ($this) {
            self::Urssaf => 'Urssaf',
            self::Retirement => 'Retraite complémentaire',
            self::Provident => 'Prévoyance',
            self::Health => 'Mutuelle (complémentaire santé)',
        };
    }

    /**
     * The account its contributions are owed on (step 4.5) when the body names none: 431000 « Sécurité
     * sociale » for the Urssaf, 437000 « Autres organismes sociaux » for the others (a dossier that keeps
     * one sub-account per body names it on the body).
     */
    public function defaultAccount(): string
    {
        return self::Urssaf === $this ? '431000' : '437000';
    }

    /** The charge account of its employer's contributions (step 4.5) when the body names none: 645 by kind of body. */
    public function defaultChargeAccount(): string
    {
        return match ($this) {
            self::Urssaf => '645100',
            self::Retirement => '645300',
            self::Health => '645200',
            self::Provident => '645800',
        };
    }

    /** What the body's reference is called: the Urssaf account, a contract number. */
    public function referenceLabel(): string
    {
        return self::Urssaf === $this ? 'Numéro de compte' : 'Numéro d’adhérent ou de contrat';
    }
}
