<?php

declare(strict_types=1);

namespace App\Enum\Accounting;

/**
 * How much the tool helps and stops a student (design-ux.md §§ 1.2 A20 and 3.4, « Niveau
 * d'assistance »): guided (G), blocking controls (B), warnings (A), no safety net (0). A mission
 * chooses (step 2.9), a student's own level overrides it (step 2.8); « Avertissements » is the
 * default. What each level shows, proposes and stops is App\Service\Shared\Assistance\Assistance's
 * to say, never a comparison of the level elsewhere.
 */
enum AssistanceLevel: string
{
    case Guided = 'guided';
    case Blocking = 'blocking';
    case Warnings = 'warnings';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Guided => 'Guidé',
            self::Blocking => 'Contrôles bloquants',
            self::Warnings => 'Avertissements',
            self::None => 'Aucun filet',
        };
    }
}
