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

    /** What the body's reference is called: the Urssaf account, a contract number. */
    public function referenceLabel(): string
    {
        return self::Urssaf === $this ? 'Numéro de compte' : 'Numéro d’adhérent ou de contrat';
    }
}
