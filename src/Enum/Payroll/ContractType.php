<?php

declare(strict_types=1);

namespace App\Enum\Payroll;

use App\Enum\Shared\BadgeTone;
use App\Enum\Shared\HasBadge;

/**
 * The employment contracts the referentiel's cases need (lot 4): open-ended, fixed-term, and the
 * apprenticeship contract (a fixed-term contract with its own rules: a diploma, an end, the
 * apprentice's exemptions read by the payslip engine).
 */
enum ContractType: string implements HasBadge
{
    case Permanent = 'permanent';
    case FixedTerm = 'fixed_term';
    case Apprenticeship = 'apprenticeship';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'CDI',
            self::FixedTerm => 'CDD',
            self::Apprenticeship => 'Apprentissage',
        };
    }

    /** The long name, for the fiche and the select. */
    public function longLabel(): string
    {
        return match ($this) {
            self::Permanent => 'Contrat à durée indéterminée (CDI)',
            self::FixedTerm => 'Contrat à durée déterminée (CDD)',
            self::Apprenticeship => 'Contrat d’apprentissage',
        };
    }

    public function badgeTone(): BadgeTone
    {
        return self::Permanent === $this ? BadgeTone::Neutral : BadgeTone::Info;
    }

    /** A fixed-term contract and an apprenticeship end on a date the contract names. */
    public function hasEnd(): bool
    {
        return self::Permanent !== $this;
    }
}
